<?php
/**
 * WC Subscriptions renewal conformance driver for the WooPayments merge harness.
 *
 * This file is normally executed through `wp eval-file -` by
 * subscriptions-renewal-gate.sh. The `normalize` mode is intentionally local
 * PHP-only so the shell wrapper can compare reference and target facts without
 * requiring jq/python.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

$tool_args = isset( $args ) && is_array( $args ) ? $args : array_slice( $argv ?? array(), 1 );
$mode      = $tool_args[0] ?? '';

if ( 'normalize' === $mode ) {
	woopayments_merge_subscriptions_normalize_file( $tool_args[1] ?? '' );
	exit( 0 );
}

if ( 'preflight' === $mode ) {
	$role = $tool_args[1] ?? '';
	woopayments_merge_subscriptions_emit( woopayments_merge_subscriptions_preflight( $role ) );
	exit( 0 );
}

if ( 'drive' === $mode ) {
	$subscription_id = isset( $tool_args[1] ) ? (int) $tool_args[1] : 0;
	$facts           = woopayments_merge_subscriptions_drive_renewal( $subscription_id );
	woopayments_merge_subscriptions_emit( $facts );
	if ( empty( $facts['success'] ) ) {
		exit( 1 );
	}
	exit( 0 );
}

woopayments_merge_subscriptions_emit(
	array(
		'success' => false,
		'mode'    => $mode,
		'errors'  => array( 'Unknown mode. Use preflight, drive, or normalize.' ),
	)
);
exit( 2 );

/**
 * Emit a single JSON line.
 *
 * @param array $payload Payload.
 */
function woopayments_merge_subscriptions_emit( array $payload ): void {
	echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) . "\n";
}

/**
 * Run store-level preflight checks.
 *
 * @param string $role Store role: ref or target.
 * @return array
 */
function woopayments_merge_subscriptions_preflight( string $role ): array {
	$errors = array();

	if ( ! in_array( $role, array( 'ref', 'target' ), true ) ) {
		$errors[] = 'Preflight role must be ref or target.';
	}

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$subscriptions_active = class_exists( 'WC_Subscriptions' ) || function_exists( 'wcs_get_subscription' );
	$wcpay_plugin_active  = function_exists( 'is_plugin_active' ) && is_plugin_active( 'woocommerce-payments/woocommerce-payments.php' );
	$subs_plugin_active   = function_exists( 'is_plugin_active' ) && is_plugin_active( 'woocommerce-subscriptions/woocommerce-subscriptions.php' );
	$gateway              = woopayments_merge_subscriptions_get_gateway();
	$gateway_class        = is_object( $gateway ) ? get_class( $gateway ) : '';
	$gateway_id           = is_object( $gateway ) && isset( $gateway->id ) ? (string) $gateway->id : '';
	$required_supports    = array(
		'multiple_subscriptions',
		'subscription_cancellation',
		'subscription_payment_method_change_admin',
		'subscription_payment_method_change_customer',
		'subscription_payment_method_change',
		'subscription_reactivation',
		'subscription_suspension',
		'subscriptions',
	);
	$supports             = array();

	foreach ( $required_supports as $support ) {
		$supports[ $support ] = woopayments_merge_subscriptions_gateway_supports( $gateway, $support );
		if ( ! $supports[ $support ] ) {
			$errors[] = "Gateway is missing required support: {$support}.";
		}
	}

	$gateway_scheduled_payments = woopayments_merge_subscriptions_gateway_supports( $gateway, 'gateway_scheduled_payments' );
	$amount_changes            = woopayments_merge_subscriptions_gateway_supports( $gateway, 'subscription_amount_changes' );
	$date_changes              = woopayments_merge_subscriptions_gateway_supports( $gateway, 'subscription_date_changes' );
	$supports['subscription_amount_changes'] = $amount_changes;
	$supports['subscription_date_changes']   = $date_changes;
	$supports['gateway_scheduled_payments']  = $gateway_scheduled_payments;

	if ( $gateway_scheduled_payments ) {
		$errors[] = 'Gateway advertises gateway_scheduled_payments; this gate requires regular WC Subscriptions renewal mode.';
	}
	if ( ! $amount_changes || ! $date_changes ) {
		$errors[] = 'Gateway is missing regular WC Subscriptions amount/date change supports.';
	}

	$scheduled_hook = 'woocommerce_scheduled_subscription_payment_woocommerce_payments';
	$failing_hook   = 'woocommerce_subscription_failing_payment_method_updated_woocommerce_payments';
	$requires_action_hook = 'woocommerce_woocommerce_payments_payment_requires_action';
	$email_facts    = woopayments_merge_subscriptions_email_class_facts();
	$hooks          = array(
		$scheduled_hook        => woopayments_merge_subscriptions_hook_facts( $scheduled_hook ),
		$failing_hook          => woopayments_merge_subscriptions_hook_facts( $failing_hook ),
		$requires_action_hook  => woopayments_merge_subscriptions_hook_facts( $requires_action_hook ),
	);

	if ( ! $subscriptions_active ) {
		$errors[] = 'WC Subscriptions is not active or loaded.';
	}
	if ( ! is_object( $gateway ) || 'woocommerce_payments' !== $gateway_id ) {
		$errors[] = 'WooPayments gateway woocommerce_payments is not registered.';
	}
	if ( empty( $hooks[ $scheduled_hook ]['registered'] ) ) {
		$errors[] = "{$scheduled_hook} has no registered callback.";
	}
	if ( empty( $hooks[ $failing_hook ]['registered'] ) ) {
		$errors[] = "{$failing_hook} has no registered callback.";
	}
	if ( empty( $hooks[ $requires_action_hook ]['registered'] ) ) {
		$errors[] = "{$requires_action_hook} has no registered callback.";
	}
	if ( empty( $email_facts['failed_renewal_authentication']['registered'] ) ) {
		$errors[] = 'Failed renewal authentication customer email is not registered.';
	} elseif ( 'failed_renewal_authentication' !== $email_facts['failed_renewal_authentication']['id'] ) {
		$errors[] = 'Failed renewal authentication customer email has the wrong ID.';
	} elseif ( empty( $email_facts['failed_renewal_authentication']['requires_action_trigger_registered'] ) ) {
		$errors[] = 'Failed renewal authentication customer email is not hooked to the requires-action event.';
	}
	if ( empty( $email_facts['failed_authentication_requested']['registered'] ) ) {
		$errors[] = 'Failed authentication retry admin email is not registered.';
	} elseif ( 'failed_authentication_requested' !== $email_facts['failed_authentication_requested']['id'] ) {
		$errors[] = 'Failed authentication retry admin email has the wrong ID.';
	}

	if ( 'ref' === $role && ! $wcpay_plugin_active ) {
		$errors[] = 'Reference store must have the separate WooPayments plugin active.';
	}
	if ( 'target' === $role && $wcpay_plugin_active ) {
		$errors[] = 'Target store must keep the separate WooPayments plugin inactive.';
	}
	if ( 'target' === $role && false === strpos( $gateway_class, 'NativeWooPaymentsGateway' ) ) {
		$errors[] = 'Target WooPayments gateway is not the native NativeWooPaymentsGateway class.';
	}

	return array(
		'success'                => empty( $errors ),
		'mode'                   => 'preflight',
		'role'                   => $role,
		'errors'                 => $errors,
		'subscriptions_active'   => $subscriptions_active,
		'subscriptions_plugin_active' => $subs_plugin_active,
		'woopayments_plugin_active'   => $wcpay_plugin_active,
		'gateway_id'             => $gateway_id,
		'gateway_class'          => $gateway_class,
		'gateway_supports'       => $supports,
		'hooks'                  => $hooks,
		'emails'                 => $email_facts,
	);
}

/**
 * Drive a single subscription renewal and collect normalized facts.
 *
 * @param int $subscription_id Subscription ID.
 * @return array
 */
function woopayments_merge_subscriptions_drive_renewal( int $subscription_id ): array {
	$errors = array();
	$emails = array();

	if ( $subscription_id <= 0 ) {
		return woopayments_merge_subscriptions_base_drive_facts( $subscription_id, null, null, array( 'A positive subscription ID is required.' ), $emails );
	}
	if ( ! function_exists( 'wcs_get_subscription' ) || ! class_exists( 'WC_Subscriptions_Manager' ) || ! class_exists( 'WC_Subscriptions_Payment_Gateways' ) ) {
		return woopayments_merge_subscriptions_base_drive_facts( $subscription_id, null, null, array( 'WC Subscriptions renewal classes/functions are unavailable.' ), $emails );
	}

	$subscription = wcs_get_subscription( $subscription_id );
	if ( ! $subscription instanceof WC_Subscription ) {
		return woopayments_merge_subscriptions_base_drive_facts( $subscription_id, null, null, array( 'Subscription could not be loaded.' ), $emails );
	}

	if ( 'woocommerce_payments' !== $subscription->get_payment_method() ) {
		$errors[] = 'Subscription payment method is not woocommerce_payments.';
	}
	if ( ! $subscription->has_status( array( 'active', 'on-hold' ) ) ) {
		$errors[] = 'Subscription is not active or on-hold, so it is not safely renewable by this gate.';
	}

	add_filter(
		'woocommerce_mail_callback',
		static function () {
			return static function () {
				return true;
			};
		},
		PHP_INT_MAX
	);

	add_filter(
		'woocommerce_mail_callback_params',
		static function ( $params, $email = null ) use ( &$emails ) {
			$emails[] = woopayments_merge_subscriptions_email_fact( is_array( $params ) ? $params : array(), $email );
			return $params;
		},
		PHP_INT_MAX,
		2
	);

	$renewal_order = null;

	if ( empty( $errors ) ) {
		try {
			$renewal_order = WC_Subscriptions_Manager::process_renewal(
				$subscription_id,
				$subscription->get_status(),
				'Renewal processed by WooPayments merge conformance gate.'
			);
		} catch ( Throwable $e ) {
			$errors[] = 'process_renewal threw ' . get_class( $e ) . ': ' . $e->getMessage();
		}

		if ( ! $renewal_order instanceof WC_Order ) {
			$renewal_order = woopayments_merge_subscriptions_get_last_renewal_order( $subscription_id );
		}

		if ( $renewal_order instanceof WC_Order ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions(
					'woocommerce_scheduled_subscription_payment',
					array( 'subscription_id' => $subscription_id ),
					'wc_subscription_scheduled_event'
				);
			} else {
				$errors[] = 'Action Scheduler as_unschedule_all_actions() is unavailable.';
			}

			try {
				WC_Subscriptions_Payment_Gateways::gateway_scheduled_subscription_payment( $subscription_id );
			} catch ( Throwable $e ) {
				$errors[] = 'gateway_scheduled_subscription_payment threw ' . get_class( $e ) . ': ' . $e->getMessage();
			}

			$renewal_order = wc_get_order( $renewal_order->get_id() );
		} else {
			$errors[] = 'process_renewal did not create a renewal order.';
		}
	}

	$subscription = wcs_get_subscription( $subscription_id );
	$facts        = woopayments_merge_subscriptions_base_drive_facts( $subscription_id, $subscription, $renewal_order, $errors, $emails );

	if ( ! empty( $facts['success_checks_failed'] ) ) {
		$facts['errors'] = array_values( array_merge( $facts['errors'], $facts['success_checks_failed'] ) );
	}

	$facts['success'] = empty( $facts['errors'] );
	return $facts;
}

/**
 * Build the stable facts payload for a renewal run.
 *
 * @param int                  $subscription_id Subscription ID.
 * @param WC_Subscription|null $subscription Subscription.
 * @param WC_Order|null        $renewal_order Renewal order.
 * @param array                $errors Errors.
 * @param array                $emails Captured email facts.
 * @return array
 */
function woopayments_merge_subscriptions_base_drive_facts( int $subscription_id, $subscription, $renewal_order, array $errors, array $emails ): array {
	$renewal_order_id = $renewal_order instanceof WC_Order ? (int) $renewal_order->get_id() : 0;
	$belongs          = $renewal_order_id > 0 ? woopayments_merge_subscriptions_renewal_belongs_to_subscription( $renewal_order_id, $subscription_id ) : false;
	$contains_exists  = function_exists( 'wcs_order_contains_renewal' );
	$contains         = $contains_exists && $renewal_order_id > 0 ? (bool) wcs_order_contains_renewal( $renewal_order_id ) : false;
	$payment_meta     = $renewal_order instanceof WC_Order ? woopayments_merge_subscriptions_meta_presence(
		$renewal_order,
		array(
			'_transaction_id',
			'_intent_id',
			'_charge_id',
			'_payment_method_id',
			'_stripe_customer_id',
			'_wcpay_payment_transaction_id',
			'_wcpay_payment_method_details',
		)
	) : array();
	$subscription_meta = $subscription instanceof WC_Subscription ? woopayments_merge_subscriptions_meta_presence(
		$subscription,
		array(
			'_payment_method_id',
			'_stripe_customer_id',
			'_schedule_next_payment',
		)
	) : array();
	$token_presence   = woopayments_merge_subscriptions_token_presence( $subscription, $renewal_order );
	$customer_meta    = woopayments_merge_subscriptions_customer_meta_presence( $subscription );
	$failed_checks    = array();

	if ( ! $renewal_order instanceof WC_Order ) {
		$failed_checks[] = 'No renewal order was captured.';
	}
	if ( $renewal_order instanceof WC_Order && ! in_array( $renewal_order->get_status(), array( 'processing', 'completed' ), true ) ) {
		$failed_checks[] = 'Renewal order is not processing or completed.';
	}
	if ( $subscription instanceof WC_Subscription && 'active' !== $subscription->get_status() ) {
		$failed_checks[] = 'Subscription is not active after renewal.';
	}
	if ( $renewal_order instanceof WC_Order && 'woocommerce_payments' !== $renewal_order->get_payment_method() ) {
		$failed_checks[] = 'Renewal order payment method is not woocommerce_payments.';
	}
	if ( ! $belongs ) {
		$failed_checks[] = 'Renewal order does not belong to the subscription.';
	}
	if ( ! $contains_exists || ! $contains ) {
		$failed_checks[] = 'wcs_order_contains_renewal() is unavailable or returned false.';
	}
	if ( empty( $payment_meta['_transaction_id'] ) && empty( $payment_meta['_intent_id'] ) && empty( $payment_meta['_charge_id'] ) && empty( $payment_meta['_wcpay_payment_transaction_id'] ) ) {
		$failed_checks[] = 'Renewal order has no transaction, intent, or charge meta.';
	}
	if ( empty( $payment_meta['_payment_method_id'] ) && empty( $token_presence['renewal_has_tokens'] ) && empty( $token_presence['subscription_has_tokens'] ) ) {
		$failed_checks[] = 'Renewal lacks payment method id and token evidence.';
	}
	if ( empty( $payment_meta['_stripe_customer_id'] ) && empty( $subscription_meta['_stripe_customer_id'] ) && empty( $customer_meta['_wcpay_customer_id_test'] ) && empty( $customer_meta['_wcpay_customer_id_live'] ) && empty( $customer_meta['_wcpay_customer_id'] ) ) {
		$failed_checks[] = 'Renewal lacks customer meta evidence.';
	}
	if ( empty( $emails ) ) {
		$failed_checks[] = 'No renewal email evidence was captured.';
	}

	return array(
		'success'                      => false,
		'mode'                         => 'drive',
		'errors'                       => array_values( $errors ),
		'success_checks_failed'        => array_values( $failed_checks ),
		'subscription_id'              => $subscription_id,
		'subscription_status'          => $subscription instanceof WC_Subscription ? $subscription->get_status() : '',
		'subscription_payment_method'  => $subscription instanceof WC_Subscription ? $subscription->get_payment_method() : '',
		'renewal_order_id'             => $renewal_order_id,
		'renewal_order_status'         => $renewal_order instanceof WC_Order ? $renewal_order->get_status() : '',
		'renewal_order_payment_method' => $renewal_order instanceof WC_Order ? $renewal_order->get_payment_method() : '',
		'renewal_belongs_to_subscription' => $belongs,
		'wcs_order_contains_renewal_exists' => $contains_exists,
		'wcs_order_contains_renewal'   => $contains,
		'payment_meta_presence'        => $payment_meta,
		'subscription_meta_presence'   => $subscription_meta,
		'token_presence'               => $token_presence,
		'customer_meta_presence'       => $customer_meta,
		'emails'                       => woopayments_merge_subscriptions_sort_emails( $emails ),
	);
}

/**
 * Get the WooPayments gateway.
 *
 * @return object|null
 */
function woopayments_merge_subscriptions_get_gateway() {
	if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->payment_gateways() ) {
		return null;
	}
	$gateways = WC()->payment_gateways()->payment_gateways();
	return $gateways['woocommerce_payments'] ?? null;
}

/**
 * Check a gateway support flag.
 *
 * @param object|null $gateway Gateway.
 * @param string      $support Support key.
 * @return bool
 */
function woopayments_merge_subscriptions_gateway_supports( $gateway, string $support ): bool {
	if ( ! is_object( $gateway ) ) {
		return false;
	}
	if ( method_exists( $gateway, 'supports' ) ) {
		return (bool) $gateway->supports( $support );
	}
	return isset( $gateway->supports ) && is_array( $gateway->supports ) && in_array( $support, $gateway->supports, true );
}

/**
 * Get WooPayments failed-renewal authentication email registration facts.
 *
 * @return array
 */
function woopayments_merge_subscriptions_email_class_facts(): array {
	$facts = array(
		'failed_renewal_authentication' => array(
			'key'                                => 'WC_Payments_Email_Failed_Renewal_Authentication',
			'registered'                         => false,
			'id'                                 => '',
			'class'                              => '',
			'requires_action_trigger_registered' => false,
			'requires_action_trigger_priority'   => false,
		),
		'failed_authentication_requested' => array(
			'key'        => 'WC_Payments_Email_Failed_Authentication_Retry',
			'registered' => false,
			'id'         => '',
			'class'      => '',
		),
	);

	if ( ! function_exists( 'WC' ) || ! WC() || ! method_exists( WC(), 'mailer' ) ) {
		return $facts;
	}

	$mailer = WC()->mailer();
	$emails = method_exists( $mailer, 'get_emails' ) ? $mailer->get_emails() : array();

	foreach ( $facts as $name => $fact ) {
		$key   = $fact['key'];
		$email = is_array( $emails ) && isset( $emails[ $key ] ) ? $emails[ $key ] : null;

		if ( ! is_object( $email ) ) {
			continue;
		}

		$facts[ $name ]['registered'] = true;
		$facts[ $name ]['id']         = isset( $email->id ) ? (string) $email->id : '';
		$facts[ $name ]['class']      = get_class( $email );

		if ( 'failed_renewal_authentication' === $name ) {
			$priority = has_action( 'woocommerce_woocommerce_payments_payment_requires_action', array( $email, 'trigger' ) );
			$facts[ $name ]['requires_action_trigger_registered'] = false !== $priority;
			$facts[ $name ]['requires_action_trigger_priority']   = false === $priority ? false : (int) $priority;
		}
	}

	return $facts;
}

/**
 * Get hook registration facts.
 *
 * @param string $hook Hook name.
 * @return array
 */
function woopayments_merge_subscriptions_hook_facts( string $hook ): array {
	global $wp_filter;
	$callbacks = array();

	if ( isset( $wp_filter[ $hook ] ) && is_object( $wp_filter[ $hook ] ) && isset( $wp_filter[ $hook ]->callbacks ) ) {
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $group ) {
			foreach ( $group as $callback ) {
				$callbacks[] = array(
					'priority' => (int) $priority,
					'callback' => woopayments_merge_subscriptions_callback_name( $callback['function'] ?? null ),
				);
			}
		}
	}

	return array(
		'registered' => false !== has_action( $hook ),
		'callbacks'  => $callbacks,
	);
}

/**
 * Normalize a callback name.
 *
 * @param mixed $callback Callback.
 * @return string
 */
function woopayments_merge_subscriptions_callback_name( $callback ): string {
	if ( is_string( $callback ) ) {
		return $callback;
	}
	if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
		$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
		return $class . '::' . (string) $callback[1];
	}
	if ( $callback instanceof Closure ) {
		return 'Closure';
	}
	return gettype( $callback );
}

/**
 * Get the last renewal order for a subscription.
 *
 * @param int $subscription_id Subscription ID.
 * @return WC_Order|null
 */
function woopayments_merge_subscriptions_get_last_renewal_order( int $subscription_id ) {
	$subscription = wcs_get_subscription( $subscription_id );
	if ( ! $subscription instanceof WC_Subscription || ! method_exists( $subscription, 'get_last_order' ) ) {
		return null;
	}
	$order = $subscription->get_last_order( 'all', 'renewal' );
	return $order instanceof WC_Order ? $order : null;
}

/**
 * Check renewal ownership.
 *
 * @param int $renewal_order_id Renewal order ID.
 * @param int $subscription_id Subscription ID.
 * @return bool
 */
function woopayments_merge_subscriptions_renewal_belongs_to_subscription( int $renewal_order_id, int $subscription_id ): bool {
	if ( ! function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
		return false;
	}
	$subscriptions = wcs_get_subscriptions_for_renewal_order( $renewal_order_id );
	foreach ( $subscriptions as $subscription ) {
		if ( $subscription instanceof WC_Subscription && (int) $subscription->get_id() === $subscription_id ) {
			return true;
		}
	}
	return false;
}

/**
 * Get meta presence for an order-like object.
 *
 * @param WC_Order $order Order.
 * @param array    $keys Meta keys.
 * @return array
 */
function woopayments_merge_subscriptions_meta_presence( WC_Order $order, array $keys ): array {
	$out = array();
	foreach ( $keys as $key ) {
		if ( '_transaction_id' === $key ) {
			$value = $order->get_transaction_id();
		} else {
			$value = $order->get_meta( $key, true );
		}
		$out[ $key ] = '' !== (string) $value;
	}
	return $out;
}

/**
 * Get token evidence.
 *
 * @param WC_Subscription|null $subscription Subscription.
 * @param WC_Order|null        $renewal_order Renewal order.
 * @return array
 */
function woopayments_merge_subscriptions_token_presence( $subscription, $renewal_order ): array {
	$subscription_tokens = $subscription instanceof WC_Subscription && method_exists( $subscription, 'get_payment_tokens' ) ? $subscription->get_payment_tokens() : array();
	$renewal_tokens      = $renewal_order instanceof WC_Order && method_exists( $renewal_order, 'get_payment_tokens' ) ? $renewal_order->get_payment_tokens() : array();
	$user_id             = $subscription instanceof WC_Subscription ? (int) $subscription->get_user_id() : 0;
	$customer_tokens     = $user_id > 0 && class_exists( 'WC_Payment_Tokens' ) ? WC_Payment_Tokens::get_customer_tokens( $user_id, 'woocommerce_payments' ) : array();

	return array(
		'subscription_has_tokens' => ! empty( $subscription_tokens ),
		'renewal_has_tokens'      => ! empty( $renewal_tokens ),
		'customer_has_tokens'     => ! empty( $customer_tokens ),
	);
}

/**
 * Get customer meta evidence.
 *
 * @param WC_Subscription|null $subscription Subscription.
 * @return array
 */
function woopayments_merge_subscriptions_customer_meta_presence( $subscription ): array {
	$user_id = $subscription instanceof WC_Subscription ? (int) $subscription->get_user_id() : 0;
	$keys    = array( '_wcpay_customer_id', '_wcpay_customer_id_test', '_wcpay_customer_id_live' );
	$out     = array();
	foreach ( $keys as $key ) {
		$out[ $key ] = $user_id > 0 && '' !== (string) get_user_meta( $user_id, $key, true );
	}
	return $out;
}

/**
 * Build an email fact from WooCommerce mail callback params.
 *
 * @param array $params Mail callback params.
 * @param mixed $email Email object.
 * @return array
 */
function woopayments_merge_subscriptions_email_fact( array $params, $email ): array {
	return array(
		'id'      => is_object( $email ) && isset( $email->id ) ? (string) $email->id : '',
		'class'   => is_object( $email ) ? get_class( $email ) : '',
		'subject' => woopayments_merge_subscriptions_normalize_subject( (string) ( $params[1] ?? '' ) ),
	);
}

/**
 * Normalize an email subject enough for cross-store parity.
 *
 * @param string $subject Subject.
 * @return string
 */
function woopayments_merge_subscriptions_normalize_subject( string $subject ): string {
	$subject = strtolower( wp_strip_all_tags( $subject ) );
	$site    = strtolower( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
	$site    = trim( (string) preg_replace( '/\s+/', ' ', $site ) );
	if ( '' !== $site ) {
		$subject = preg_replace( '/' . preg_quote( $site, '/' ) . '/', '<site>', $subject );
	}
	$subject = preg_replace( '/https?:\/\/\S+/', '<url>', $subject );
	$subject = preg_replace( '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', '<email>', $subject );
	$subject = preg_replace( '/#[0-9]+/', '#<id>', $subject );
	$subject = preg_replace( '/\b[0-9]+\b/', '<num>', $subject );
	$subject = preg_replace( '/\s+/', ' ', $subject );
	return trim( (string) $subject );
}

/**
 * Sort emails by stable fields.
 *
 * @param array $emails Email facts.
 * @return array
 */
function woopayments_merge_subscriptions_sort_emails( array $emails ): array {
	usort(
		$emails,
		static function ( array $a, array $b ): int {
			return strcmp( implode( '|', $a ), implode( '|', $b ) );
		}
	);
	return $emails;
}

/**
 * Normalize a raw driver JSON file for reference/target comparison.
 *
 * @param string $path JSON path.
 */
function woopayments_merge_subscriptions_normalize_file( string $path ): void {
	if ( '' === $path || ! is_readable( $path ) ) {
		fwrite( STDERR, "Cannot read JSON file for normalization.\n" );
		exit( 2 );
	}

	$payload = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $payload ) ) {
		fwrite( STDERR, "Invalid JSON file for normalization: {$path}\n" );
		exit( 2 );
	}

	$normalized = array(
		'success'                         => (bool) ( $payload['success'] ?? false ),
		'subscription_status'             => (string) ( $payload['subscription_status'] ?? '' ),
		'subscription_payment_method'     => (string) ( $payload['subscription_payment_method'] ?? '' ),
		'renewal_order_status'            => (string) ( $payload['renewal_order_status'] ?? '' ),
		'renewal_order_payment_method'    => (string) ( $payload['renewal_order_payment_method'] ?? '' ),
		'renewal_belongs_to_subscription' => (bool) ( $payload['renewal_belongs_to_subscription'] ?? false ),
		'wcs_order_contains_renewal_exists' => (bool) ( $payload['wcs_order_contains_renewal_exists'] ?? false ),
		'wcs_order_contains_renewal'      => (bool) ( $payload['wcs_order_contains_renewal'] ?? false ),
		'payment_meta_presence'           => $payload['payment_meta_presence'] ?? array(),
		'subscription_meta_presence'      => $payload['subscription_meta_presence'] ?? array(),
		'token_presence'                  => $payload['token_presence'] ?? array(),
		'customer_meta_presence'          => $payload['customer_meta_presence'] ?? array(),
		'emails'                          => $payload['emails'] ?? array(),
	);

	woopayments_merge_subscriptions_recursive_ksort( $normalized );
	echo json_encode( $normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
}

/**
 * Recursively sort associative arrays.
 *
 * @param array $value Value.
 */
function woopayments_merge_subscriptions_recursive_ksort( array &$value ): void {
	foreach ( $value as &$child ) {
		if ( is_array( $child ) ) {
			woopayments_merge_subscriptions_recursive_ksort( $child );
		}
	}
	ksort( $value );
}
