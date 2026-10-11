<?php
/**
 * WooPaymentsSubscriptionsController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcomeApplyException;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCurrencyUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderMode;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOutcomeMetadataMapper;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProviderGatewayAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Exception;
use Throwable;
use WC_Order;
use WC_Payment_Token;

defined( 'ABSPATH' ) || exit;

/**
 * Charges WooCommerce Subscriptions renewals, updates a failing renewal's payment method and forces manual renewal where
 * WooPayments cannot renew, for the WooPayments gateways.
 *
 * The client attaches these hooks whenever WooPayments loads, whether or not its gateway is enabled (client 11.1.0
 * `includes/class-wc-payments.php:630-649`, `includes/compat/subscriptions/trait-wc-payment-gateway-wcpay-subscriptions.php:274-298`),
 * so a connected store with the gateway disabled still renews. Collaborators are resolved when a renewal runs, so
 * attaching the hooks builds nothing else.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsSubscriptionsController implements RegisterHooksInterface {

	/**
	 * Whether the renewal hooks were attached in this request.
	 *
	 * @var bool
	 */
	private static bool $attached = false;

	/**
	 * Runtime ownership arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * Payment processing service, resolved when a renewal runs.
	 *
	 * @var PaymentProcessingService|null
	 */
	private ?PaymentProcessingService $processing_service = null;

	/**
	 * WooPayments provider, resolved when a renewal runs.
	 *
	 * @var WooPaymentsProvider|null
	 */
	private ?WooPaymentsProvider $provider = null;

	/**
	 * WooPayments token service, resolved on first use.
	 *
	 * @var WooPaymentsTokenService|null
	 */
	private ?WooPaymentsTokenService $token_service = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter $arbiter Runtime ownership arbiter.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Attach the renewal hooks on `plugins_loaded` priority 11 when native owns payments.
	 *
	 * That is when the client detects Subscriptions and attaches (client 11.1.0 `woocommerce-payments.php:214`), after
	 * Subscriptions has loaded and before WooCommerce builds its emails.
	 */
	public function register(): void {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		if ( did_action( 'plugins_loaded' ) ) {
			$this->handle_plugins_loaded();
			return;
		}

		add_action( 'plugins_loaded', array( $this, 'handle_plugins_loaded' ), 11 );
	}

	/**
	 * Attach the renewal hooks when Subscriptions is available.
	 *
	 * @internal
	 */
	public function handle_plugins_loaded(): void {
		if ( ! WooPaymentsSubscriptionMethodPolicy::is_subscriptions_available() ) {
			return;
		}

		self::attach( $this );
	}

	/**
	 * Attach the renewal hooks once per request, pointing them at the given controller.
	 *
	 * Called on `plugins_loaded` on native-owned requests, or by the card gateway when it is built first.
	 *
	 * @param self $controller Controller that handles renewals, failing-method updates and forced manual renewal.
	 * @return bool Whether this call attached the hooks.
	 */
	public static function attach( self $controller ): bool {
		if ( self::$attached ) {
			return false;
		}
		self::$attached = true;

		if ( false === has_filter( 'woocommerce_email_classes', array( WooPaymentsGateway::class, 'add_subscription_emails' ) ) ) {
			add_filter( 'woocommerce_email_classes', array( WooPaymentsGateway::class, 'add_subscription_emails' ), 20 );
		}

		add_action( 'woocommerce_checkout_subscription_created', array( $controller, 'maybe_force_subscription_to_manual' ), 10, 1 );

		foreach ( WooPaymentsSubscriptionMethodPolicy::get_reusable_gateway_ids() as $gateway_id ) {
			add_action( 'woocommerce_scheduled_subscription_payment_' . $gateway_id, array( $controller, 'scheduled_subscription_payment' ), 10, 2 );
			add_action( 'woocommerce_subscription_failing_payment_method_updated_' . $gateway_id, array( $controller, 'update_failing_payment_method' ), 10, 2 );
		}

		wc_get_container()->get( WooPaymentsSubscriptionAdminPaymentMethodHandler::class )->register_hooks();

		if ( ! WooPaymentsSubscriptionMethodPolicy::is_duplicate_site() ) {
			add_filter( 'wcs_renewal_order_items', array( self::class, 'check_renewal_mode' ), 10, 3 );
		}

		// Subscriptions before its data copier filtered only the meta query; the client switches the same way for its own
		// renewal meta (client 11.1.0 `includes/compat/subscriptions/trait-wc-payment-gateway-wcpay-subscriptions.php:315-320`).
		if ( wc_get_container()->get( LegacyProxy::class )->call_function( 'class_exists', 'WC_Subscriptions_Data_Copier' ) ) {
			add_filter( 'wc_subscriptions_object_data', array( self::class, 'exclude_charge_idempotency_key' ), 10, 1 );
		} else {
			foreach ( array( 'subscription', 'parent', 'renewal_order', 'resubscribe_order' ) as $copy_type ) {
				add_filter( "wcs_{$copy_type}_meta_query", array( self::class, 'exclude_charge_idempotency_key_from_meta_query' ), 10, 1 );
			}
		}

		return true;
	}

	/**
	 * Leave the charge idempotency key and its ambiguity record out of the data Subscriptions copies between orders and subscriptions.
	 *
	 * The key belongs to one order's charge. Subscriptions copies a parent order's meta to its subscription, and the
	 * subscription's meta to every renewal, so a key kept after an ambiguous parent charge would be sent again by a
	 * renewal with another body, which the provider refuses within 24 hours (review 37 F1). The record of that ambiguous
	 * failure belongs to the same charge, so it stays behind with the key. So does the record of an ambiguous refund: on a
	 * copy it would name another order and be deleted, but it never needs to travel.
	 *
	 * @internal
	 *
	 * @param mixed $data Data to copy, keyed by meta key.
	 * @return mixed
	 */
	public static function exclude_charge_idempotency_key( $data ) {
		if ( is_array( $data ) ) {
			unset( $data[ WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META ], $data[ WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META ], $data[ WooPaymentsProviderGatewayAdapter::REFUND_AMBIGUITY_META ] );
		}

		return $data;
	}

	/**
	 * Leave the charge idempotency key and its ambiguity record out of the meta query older Subscriptions versions copy with.
	 *
	 * @internal
	 *
	 * @param mixed $meta_query SQL query selecting the meta to copy.
	 * @return mixed
	 */
	public static function exclude_charge_idempotency_key_from_meta_query( $meta_query ) {
		if ( ! is_string( $meta_query ) ) {
			return $meta_query;
		}

		return $meta_query . sprintf( " AND `meta_key` NOT IN ('%s', '%s', '%s')", WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, WooPaymentsProviderGatewayAdapter::REFUND_AMBIGUITY_META );
	}

	/**
	 * Refuse to create a renewal order when the subscription was paid in a different WooPayments mode than the current one.
	 *
	 * A test-mode payment method cannot be charged in live mode, and the reverse, so the renewal would only fail.
	 * Client 11.1.0 `WC_Payments_Subscription_Service::check_wcpay_mode_for_subscription()`; WooCommerce Subscriptions
	 * rolls the renewal order back when this throws.
	 *
	 * @internal
	 *
	 * @param mixed $items         Items for the renewal order.
	 * @param mixed $renewal_order Renewal order.
	 * @param mixed $subscription  Subscription being renewed.
	 * @return mixed The items, unchanged.
	 * @throws \RuntimeException When the subscription's mode differs from the current mode.
	 */
	public static function check_renewal_mode( $items, $renewal_order, $subscription ) {
		unset( $renewal_order );
		if ( ! $subscription instanceof \WC_Order || ! is_callable( array( $subscription, 'get_parent' ) ) ) {
			return $items;
		}

		$parent_order = $subscription->get_parent();
		if ( ! $parent_order instanceof \WC_Order ) {
			return $items;
		}

		$subscription_mode = $parent_order->get_meta( '_wcpay_mode' );
		$current_mode      = wc_get_container()->get( WooPaymentsAccountService::class )->get_order_mode();
		if ( ! is_string( $subscription_mode ) || '' === $subscription_mode || $subscription_mode === $current_mode ) {
			return $items;
		}

		if ( WooPaymentsOrderMode::TEST === $subscription_mode ) {
			throw new \RuntimeException( esc_html__( 'Subscription was made when WooPayments was in the test mode and cannot be renewed in the live mode.', 'woocommerce' ) );
		}

		throw new \RuntimeException( esc_html__( 'Subscription was made when WooPayments was in the live mode and cannot be renewed in the test mode.', 'woocommerce' ) );
	}

	/**
	 * Charge a renewal order's saved payment method with the customer absent.
	 *
	 * WooCommerce Subscriptions calls it with two arguments, so its renewals charge under the card gateway ID, as the
	 * client's renewal handler on its card gateway does. A WooPayments gateway calling it passes its own ID.
	 *
	 * @internal
	 *
	 * @param mixed  $amount        Renewal amount.
	 * @param mixed  $renewal_order Renewal order.
	 * @param string $gateway_id    ID of the gateway the charge goes through.
	 * @return void
	 * @throws Throwable When a requires-action hook callback throws, or the token repair or applying the payment raises
	 *                   a PHP Error, so the scheduled action fails as on the client.
	 */
	public function scheduled_subscription_payment( $amount, $renewal_order, string $gateway_id = WooPaymentsPersistenceVocabulary::GATEWAY_ID ): void {
		unset( $amount );

		if ( ! $renewal_order instanceof WC_Order ) {
			return;
		}

		// Stripe charges the renewals of Stripe Billing subscriptions itself; the invoice webhooks record them.
		if ( $this->get_stripe_billing_module()->is_stripe_billed_order( $renewal_order ) ) {
			return;
		}

		$token = $this->get_payment_token_from_order( $renewal_order );
		if ( ! $token instanceof WC_Payment_Token && ! $this->is_network_saved_cards_enabled() ) {
			$token = $this->maybe_repair_renewal_order_payment_token( $renewal_order );
		}

		// Deliberate divergence: on a network forcing network-wide saved cards, the
		// extension proceeds with a null token and lets the platform resolve the
		// network card. Native has no network-card machinery, so a tokenless renewal
		// fails honestly here instead of sending a charge with no payment method.
		if ( ! $token instanceof WC_Payment_Token ) {
			$renewal_order->add_order_note( __( 'Subscription renewal failed: No saved payment method found.', 'woocommerce' ) );
			// Client 11.1.0 trait:415.
			$this->get_logger()->error( 'There is no saved payment token for order #' . $renewal_order->get_id() );
			$renewal_order->update_status( 'failed' );
			return;
		}

		$provider_data = array(
			'scheduled_subscription_payment'    => true,
			'saved_payment_method_display_name' => $token->get_display_name(),
		);
		$mandate       = $this->get_renewal_order_mandate( $renewal_order );
		if ( '' !== $mandate ) {
			$provider_data['renewal_mandate'] = $mandate;
		}

		$customer_id = $this->get_renewal_order_customer_id( $renewal_order );
		if ( '' !== $customer_id ) {
			$renewal_order->update_meta_data( '_stripe_customer_id', $customer_id );
			$renewal_order->save_meta_data();
		}

		try {
			$outcome = $this->get_processing_service()->process_checkout_outcome(
				PaymentOperationContext::for_checkout(
					$renewal_order,
					$gateway_id,
					'',
					array(
						'payment_token'       => (string) $token->get_id(),
						'save_payment_method' => false,
					),
					$provider_data
				),
				$this->get_provider()
			);
		} catch ( PaymentOutcomeApplyException $exception ) {
			// The processing service logged the failure and tried to save the payment reference on the renewal; see was_reconciliation_context_persisted().
			$failure = $exception->get_failure();
			$outcome = $exception->get_outcome();
			$this->get_logger()->log_throwable(
				'Error applying the WooPayments subscription renewal payment.',
				$failure,
				array( 'order_id' => $renewal_order->get_id() )
			);

			// Client trait:426 catches only API_Exception, so a PHP Error fails the scheduled action and leaves the renewal
			// pending (monitor ruling 2026-10-04 on renewal apply errors). A requires-action outcome still runs its hooks below.
			if ( ! $failure instanceof Exception && PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION !== $outcome->get_status() ) {
				throw $failure;
			}
		}

		$this->maybe_handle_subscription_customer_action_required( $renewal_order, $outcome );
	}

	/**
	 * Handle a scheduled renewal that requires customer authentication.
	 *
	 * @param WC_Order       $renewal_order Renewal order.
	 * @param PaymentOutcome $outcome       Provider payment outcome.
	 * @return void
	 * @throws Throwable When a requires-action hook callback throws; the renewal is left as it was.
	 */
	private function maybe_handle_subscription_customer_action_required( WC_Order $renewal_order, PaymentOutcome $outcome ): void {
		if ( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION !== $outcome->get_status() ) {
			return;
		}

		$data      = $outcome->get_data();
		$meta      = isset( $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] ) && is_array( $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] )
			? $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ]
			: array();
		$charge_id = isset( $data['charge_id'] )
			? (string) $data['charge_id']
			: ( isset( $meta['_charge_id'] ) ? (string) $meta['_charge_id'] : '' );

		try {
			/**
			 * Fires when a native WooPayments payment requires customer authentication.
			 *
			 * This intentionally keeps the standalone WooPayments plugin's action name
			 * (`woocommerce_woocommerce_payments_*`) rather than the native `woocommerce_woopayments_*`
			 * prefix, for parity: extensions hooked to the plugin's action keep working on the
			 * native runtime. Do not rename it: a new name would stop those callbacks from running.
			 *
			 * @param WC_Order $renewal_order     The renewal order that requires authentication.
			 * @param string   $intent_id         The provider payment intent ID.
			 * @param string   $payment_method_id The provider payment method ID.
			 * @param string   $customer_id       The provider customer ID.
			 * @param string   $charge_id         The provider charge ID.
			 * @param string   $currency          The order currency.
			 *
			 * @since 11.0.0
			 */
			do_action(
				'woocommerce_woocommerce_payments_payment_requires_action',
				$renewal_order,
				$outcome->get_provider_payment_id(),
				$outcome->get_payment_method_id(),
				$outcome->get_customer_id(),
				$charge_id,
				$renewal_order->get_currency()
			);
		} catch ( Throwable $exception ) {
			// Client gw:1921 does not catch, so the scheduled action fails and the renewal stays pending
			// (monitor ruling 2026-10-04 (2)); the line is written whatever the logging setting. A callback can let a
			// platform error out, so the line carries its class and the platform's codes, not its message.
			$this->get_logger()->log_throwable_always(
				'Failed to run WooPayments subscription renewal authentication hooks.',
				$exception,
				array(
					'order_id'  => $renewal_order->get_id(),
					'intent_id' => $outcome->get_provider_payment_id(),
				)
			);

			throw $exception;
		}

		if ( ! $renewal_order->has_status( 'failed' ) ) {
			$renewal_order->update_status( 'failed' );
		}

		$failure_note = $this->get_subscription_customer_action_failure_note( $renewal_order, $outcome, $charge_id );
		if ( '' !== $failure_note && ! $this->order_has_note_containing( $renewal_order, $failure_note ) ) {
			$renewal_order->add_order_note( $failure_note );
		}
	}

	/**
	 * Get the failed-renewal note for customer-action-required outcomes.
	 *
	 * @param WC_Order       $renewal_order Renewal order.
	 * @param PaymentOutcome $outcome       Provider payment outcome.
	 * @param string         $charge_id     Provider charge ID.
	 * @return string Order note.
	 */
	private function get_subscription_customer_action_failure_note( WC_Order $renewal_order, PaymentOutcome $outcome, string $charge_id ): string {
		$transaction_id = '' !== $charge_id ? $charge_id : $outcome->get_provider_payment_id();
		if ( '' === $transaction_id ) {
			return '';
		}

		return wp_kses_post(
			sprintf(
				/* translators: %1$s: the failed payment amount, %2$s: WooPayments, %3$s: transaction ID. */
				__( 'A payment of %1$s <strong>failed</strong> using %2$s (<code>%3$s</code>).', 'woocommerce' ),
				WooPaymentsCurrencyUtils::format_price_in_currency( (float) $renewal_order->get_total(), $renewal_order->get_currency() ),
				'WooPayments',
				esc_html( $transaction_id )
			)
		);
	}

	/**
	 * Tell whether an order already has a note containing the expected text.
	 *
	 * @param WC_Order $order         Order object.
	 * @param string   $expected_note Expected note text.
	 * @return bool
	 */
	private function order_has_note_containing( WC_Order $order, string $expected_note ): bool {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $note ) {
			if ( str_contains( (string) $note->content, $expected_note ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the mandate ID from the subscription parent order for a renewal order.
	 *
	 * @param WC_Order $renewal_order Renewal order.
	 * @return string Mandate ID, or an empty string when not available.
	 */
	private function get_renewal_order_mandate( WC_Order $renewal_order ): string {
		$parent_order = $this->get_subscription_parent_order_for_renewal( $renewal_order );
		if ( ! $parent_order instanceof WC_Order ) {
			return '';
		}

		return (string) $parent_order->get_meta( '_stripe_mandate_id', true );
	}

	/**
	 * Get the WooPayments customer ID from the renewal order, current subscription, or subscription parent order.
	 *
	 * @param WC_Order $renewal_order Renewal order.
	 * @return string Customer ID, or an empty string when not available.
	 */
	private function get_renewal_order_customer_id( WC_Order $renewal_order ): string {
		$customer_id = (string) $renewal_order->get_meta( '_stripe_customer_id', true );
		if ( '' !== $customer_id ) {
			return $customer_id;
		}

		$subscription = $this->get_subscription_for_renewal_order( $renewal_order );
		if ( $subscription instanceof WC_Order ) {
			$customer_id = (string) $subscription->get_meta( '_stripe_customer_id', true );
			if ( '' !== $customer_id ) {
				return $customer_id;
			}
		}

		$parent_order = $this->get_subscription_parent_order_for_renewal( $renewal_order );
		if ( ! $parent_order instanceof WC_Order ) {
			return '';
		}

		return (string) $parent_order->get_meta( '_stripe_customer_id', true );
	}

	/**
	 * Get the subscription parent order associated with a renewal order.
	 *
	 * @param WC_Order $renewal_order Renewal order.
	 * @return WC_Order|null Parent order, or null when not available.
	 */
	private function get_subscription_parent_order_for_renewal( WC_Order $renewal_order ): ?WC_Order {
		$subscription = $this->get_subscription_for_renewal_order( $renewal_order );
		if ( ! $subscription instanceof WC_Order ) {
			return null;
		}

		return $this->get_subscription_parent_order( $subscription );
	}

	/**
	 * Get a subscription's parent order.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return WC_Order|null Parent order, or null when not available.
	 */
	private function get_subscription_parent_order( WC_Order $subscription ): ?WC_Order {
		$parent_order = wc_get_order( (int) $subscription->get_parent_id() );

		return $parent_order instanceof WC_Order ? $parent_order : null;
	}

	/**
	 * Recover a renewal order's missing payment token from the parent order.
	 *
	 * A renewal can arrive without a token — the subscription's token row was deleted,
	 * or a migration dropped the link. Failing the renewal outright loses revenue the
	 * merchant can still collect: the original order's payment method ID identifies a
	 * charge-able saved method. Ports the WooPayments extension's repair.
	 *
	 * @param WC_Order $renewal_order Renewal order missing its token.
	 * @return WC_Payment_Token|null The restored token, or null when repair is impossible.
	 * @throws Throwable When the repair raises a PHP Error.
	 */
	private function maybe_repair_renewal_order_payment_token( WC_Order $renewal_order ): ?WC_Payment_Token {
		$subscription = $this->get_subscription_for_renewal_order( $renewal_order );
		if ( ! $subscription instanceof WC_Order ) {
			return null;
		}

		$parent_order = $this->get_subscription_parent_order( $subscription );
		if ( ! $parent_order instanceof WC_Order ) {
			return null;
		}

		$payment_method_id = (string) $parent_order->get_meta( '_payment_method_id', true );
		if ( '' === $payment_method_id ) {
			return null;
		}

		try {
			// The parent order is only a source for the payment method ID, never a
			// write target: attaching the token to it would fan the token out to every
			// subscription that order created, silently re-pointing sibling
			// subscriptions the customer has since moved to a different card.
			$token = $this->get_token_service()->get_or_create_token_for_user( $payment_method_id, (int) $subscription->get_customer_id() );
			if ( ! $token instanceof WC_Payment_Token ) {
				return null;
			}

			$this->get_token_service()->attach_token_to_order( $renewal_order, $token );

			$subscription_token = $this->get_payment_token_from_order( $subscription );
			if ( ! $subscription_token instanceof WC_Payment_Token || $token->get_id() !== $subscription_token->get_id() ) {
				$subscription->add_payment_token( $token );
				$subscription->add_order_note(
					sprintf(
						/* translators: %s: payment method display name. */
						__( 'The saved payment method for this subscription was missing, so WooPayments restored %s from the original order to complete the renewal.', 'woocommerce' ),
						$token->get_display_name()
					)
				);
			}

			$renewal_order->add_order_note( __( 'Recovered missing subscription payment method token from the parent order.', 'woocommerce' ) );

			return $token;
		} catch ( Throwable $exception ) {
			$this->get_logger()->log_throwable(
				'Error repairing subscription renewal payment token for order #' . $renewal_order->get_id() . '.',
				$exception,
				array( 'order_id' => $renewal_order->get_id() )
			);

			// Client trait:538 catches only Exception, so a PHP Error fails the scheduled action and leaves the
			// renewal pending (monitor ruling 2026-10-04 (3)).
			if ( ! $exception instanceof Exception ) {
				throw $exception;
			}

			return null;
		}
	}

	/**
	 * Get the subscription associated with a renewal order.
	 *
	 * @param WC_Order $renewal_order Renewal order.
	 * @return WC_Order|null Subscription order, or null when not available.
	 */
	private function get_subscription_for_renewal_order( WC_Order $renewal_order ): ?WC_Order {
		$subscriptions = array();
		if ( function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $renewal_order->get_id() );
		}

		$subscriptions = is_array( $subscriptions ) ? $subscriptions : array();
		$subscription  = reset( $subscriptions );

		return $subscription instanceof WC_Order ? $subscription : null;
	}

	/**
	 * Copy the successful renewal token to the failing subscription.
	 *
	 * @internal
	 *
	 * @param mixed $subscription  Subscription order.
	 * @param mixed $renewal_order Renewal order.
	 * @return void
	 */
	public function update_failing_payment_method( $subscription, $renewal_order ): void {
		if ( ! $subscription instanceof WC_Order || ! $renewal_order instanceof WC_Order ) {
			return;
		}

		$token = $this->get_payment_token_from_order( $renewal_order );
		if ( ! $token instanceof WC_Payment_Token ) {
			$renewal_order->add_order_note( __( 'Unable to update subscription payment method: No valid payment token or method found.', 'woocommerce' ) );
			return;
		}

		$this->get_token_service()->attach_token_to_order( $subscription, $token );
	}

	/**
	 * Force subscriptions using non-reusable WooPayments methods to manual renewal.
	 *
	 * @internal
	 *
	 * @param mixed $subscription Subscription object.
	 * @return void
	 */
	public function maybe_force_subscription_to_manual( $subscription ): void {
		if ( ! $subscription instanceof WC_Order || ! is_callable( array( $subscription, 'set_requires_manual_renewal' ) ) ) {
			return;
		}

		$gateway_id = $subscription->get_payment_method();
		if ( ! WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( $gateway_id ) || WooPaymentsSubscriptionMethodPolicy::is_reusable_gateway_id( $gateway_id ) ) {
			return;
		}

		$payment_method_type = substr( $gateway_id, strlen( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX ) );
		$subscription->update_meta_data( '_wcpay_original_payment_method_id', $gateway_id );
		$subscription->set_requires_manual_renewal( true );
		$subscription->save();
		$subscription->add_order_note(
			sprintf(
				/* translators: %s: payment method type. */
				__( 'Subscription set to manual renewal because %s is a non-reusable payment method.', 'woocommerce' ),
				$payment_method_type
			)
		);
	}

	/**
	 * Get the saved payment token from an order.
	 *
	 * @param WC_Order $order Order object.
	 * @return WC_Payment_Token|null
	 */
	private function get_payment_token_from_order( WC_Order $order ): ?WC_Payment_Token {
		return $this->get_token_service()->get_active_token_for_order( $order );
	}

	/**
	 * Tell whether the site only uses network-wide saved payment methods.
	 *
	 * On such networks the token intentionally lives outside the site, so the local
	 * repair must not run and re-localize it.
	 *
	 * @return bool
	 */
	private function is_network_saved_cards_enabled(): bool {
		return $this->get_account_service()->is_network_saved_cards_enabled();
	}

	/**
	 * Get the payment processing service.
	 *
	 * @return PaymentProcessingService
	 */
	private function get_processing_service(): PaymentProcessingService {
		if ( null === $this->processing_service ) {
			$this->processing_service = wc_get_container()->get( PaymentProcessingService::class );
		}

		return $this->processing_service;
	}

	/**
	 * Get the WooPayments provider.
	 *
	 * @return WooPaymentsProvider
	 */
	private function get_provider(): WooPaymentsProvider {
		if ( null === $this->provider ) {
			$this->provider = wc_get_container()->get( WooPaymentsProvider::class );
		}

		return $this->provider;
	}

	/**
	 * Get the WooPayments token service.
	 *
	 * @return WooPaymentsTokenService
	 */
	private function get_token_service(): WooPaymentsTokenService {
		if ( null === $this->token_service ) {
			$this->token_service = wc_get_container()->get( WooPaymentsTokenService::class );
		}

		return $this->token_service;
	}

	/**
	 * Get the WooPayments account service.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function get_account_service(): WooPaymentsAccountService {
		return wc_get_container()->get( WooPaymentsAccountService::class );
	}

	/**
	 * Get the Stripe Billing module, which answers whether Stripe bills a subscription.
	 *
	 * @return WooPaymentsStripeBillingModule
	 */
	private function get_stripe_billing_module(): WooPaymentsStripeBillingModule {
		return wc_get_container()->get( WooPaymentsStripeBillingModule::class );
	}

	/**
	 * Get the WooPayments logger, which writes only when debug logging is on (client 11.1.0 `src/Internal/Logger.php:64-91`).
	 *
	 * @return WooPaymentsLogger
	 */
	private function get_logger(): WooPaymentsLogger {
		return wc_get_container()->get( WooPaymentsLogger::class );
	}
}
