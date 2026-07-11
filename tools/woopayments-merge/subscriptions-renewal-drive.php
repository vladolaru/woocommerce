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

if ( 'evaluate-success' === $mode ) {
	$context = woopayments_merge_subscriptions_read_json_file( $tool_args[1] ?? '' );
	if ( isset( $context['__errors'] ) ) {
		woopayments_merge_subscriptions_emit(
			array(
				'success' => false,
				'mode'    => 'evaluate-success',
				'errors'  => $context['__errors'],
			)
		);
		exit( 2 );
	}

	$evaluation = woopayments_merge_subscriptions_evaluate_renewal_success_context( $context );
	$payload    = array_merge(
		array(
			'success' => empty( $evaluation['success_checks_failed'] ),
			'mode'    => 'evaluate-success',
			'errors'  => empty( $evaluation['success_checks_failed'] ) ? array() : $evaluation['success_checks_failed'],
		),
		$evaluation
	);
	woopayments_merge_subscriptions_emit( $payload );
	exit( empty( $payload['success'] ) ? 1 : 0 );
}

if ( 'preflight' === $mode ) {
	$role                = $tool_args[1] ?? '';
	$expected_gateway_id = isset( $tool_args[2] ) && '' !== (string) $tool_args[2] ? (string) $tool_args[2] : 'woocommerce_payments';
	$expected_token_type = isset( $tool_args[3] ) && '' !== (string) $tool_args[3] ? (string) $tool_args[3] : woopayments_merge_subscriptions_token_type_for_gateway( $expected_gateway_id );
	$payment_family      = isset( $tool_args[4] ) && '' !== (string) $tool_args[4] ? (string) $tool_args[4] : woopayments_merge_subscriptions_family_for_gateway( $expected_gateway_id );
	woopayments_merge_subscriptions_emit( woopayments_merge_subscriptions_preflight( $role, $expected_gateway_id, $expected_token_type, $payment_family ) );
	exit( 0 );
}

if ( 'drive' === $mode ) {
	$subscription_id     = isset( $tool_args[1] ) ? (int) $tool_args[1] : 0;
	$expected_gateway_id = isset( $tool_args[2] ) && '' !== (string) $tool_args[2] ? (string) $tool_args[2] : 'woocommerce_payments';
	$expected_token_id   = isset( $tool_args[3] ) ? (int) $tool_args[3] : 0;
	$expected_token_type = isset( $tool_args[4] ) && '' !== (string) $tool_args[4] ? (string) $tool_args[4] : woopayments_merge_subscriptions_token_type_for_gateway( $expected_gateway_id );
	$payment_family      = isset( $tool_args[5] ) && '' !== (string) $tool_args[5] ? (string) $tool_args[5] : woopayments_merge_subscriptions_family_for_gateway( $expected_gateway_id );
	$facts               = woopayments_merge_subscriptions_drive_renewal( $subscription_id, $expected_gateway_id, $expected_token_id, $expected_token_type, $payment_family );
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
		'errors'  => array( 'Unknown mode. Use preflight, drive, normalize, or evaluate-success.' ),
	)
);
exit( 2 );

/**
 * Emit a single JSON line.
 *
 * @param array $payload Payload.
 */
function woopayments_merge_subscriptions_emit( array $payload ): void {
	$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) : json_encode( $payload, JSON_UNESCAPED_SLASHES );
	echo $json . "\n";
}

/**
 * Read a JSON object from disk for local PHP-only modes.
 *
 * @param string $path JSON path.
 * @return array
 */
function woopayments_merge_subscriptions_read_json_file( string $path ): array {
	if ( '' === $path || ! is_readable( $path ) ) {
		return array( '__errors' => array( 'Cannot read JSON context file.' ) );
	}

	$payload = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $payload ) ) {
		return array( '__errors' => array( 'Invalid JSON context file.' ) );
	}

	return $payload;
}

/**
 * Run store-level preflight checks.
 *
 * @param string $role                Store role: ref or target.
 * @param string $expected_gateway_id Expected renewal gateway ID.
 * @param string $expected_token_type Expected saved-token type.
 * @param string $payment_family      Renewal policy family.
 * @return array
 */
function woopayments_merge_subscriptions_preflight( string $role, string $expected_gateway_id = 'woocommerce_payments', string $expected_token_type = 'CC', string $payment_family = 'card' ): array {
	$errors = array();
	$policy = woopayments_merge_subscriptions_payment_family_policy( $payment_family );

	if ( ! in_array( $role, array( 'ref', 'target' ), true ) ) {
		$errors[] = 'Preflight role must be ref or target.';
	}
	if ( null === $policy ) {
		$errors[] = 'Payment family must be card or sepa.';
	} elseif ( $expected_gateway_id !== $policy['gateway_id'] || $expected_token_type !== $policy['token_type'] ) {
		$errors[] = 'Expected gateway/token values do not match the selected payment family policy.';
	}

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$subscriptions_active = class_exists( 'WC_Subscriptions' ) || function_exists( 'wcs_get_subscription' );
	$wcpay_plugin_active  = function_exists( 'is_plugin_active' ) && is_plugin_active( 'woocommerce-payments/woocommerce-payments.php' );
	$subs_plugin_active   = function_exists( 'is_plugin_active' ) && is_plugin_active( 'woocommerce-subscriptions/woocommerce-subscriptions.php' );
	$gateway              = woopayments_merge_subscriptions_get_gateway( $expected_gateway_id );
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

	$scheduled_hook = 'woocommerce_scheduled_subscription_payment_' . $expected_gateway_id;
	$failing_hook   = 'woocommerce_subscription_failing_payment_method_updated_' . $expected_gateway_id;
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
	if ( ! is_object( $gateway ) || $expected_gateway_id !== $gateway_id ) {
		$errors[] = "WooPayments gateway {$expected_gateway_id} is not registered.";
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
		'payment_family'         => $payment_family,
		'expected_gateway_id'    => $expected_gateway_id,
		'expected_token_type'    => $expected_token_type,
		'gateway_supports'       => $supports,
		'hooks'                  => $hooks,
		'emails'                 => $email_facts,
	);
}

/**
 * Drive a single subscription renewal and collect normalized facts.
 *
 * @param int    $subscription_id Subscription ID.
 * @param string $expected_gateway_id Expected renewal gateway ID.
 * @param int    $expected_token_id Expected saved token ID.
 * @param string $expected_token_type Expected saved-token type.
 * @param string $payment_family Renewal policy family.
 * @return array
 */
function woopayments_merge_subscriptions_drive_renewal( int $subscription_id, string $expected_gateway_id = 'woocommerce_payments', int $expected_token_id = 0, string $expected_token_type = 'CC', string $payment_family = 'card' ): array {
	$errors = array();
	$emails = array();
	$policy = woopayments_merge_subscriptions_payment_family_policy( $payment_family );

	if ( null === $policy ) {
		$errors[] = 'Payment family must be card or sepa.';
	} elseif ( $expected_gateway_id !== $policy['gateway_id'] || $expected_token_type !== $policy['token_type'] ) {
		$errors[] = 'Expected gateway/token values do not match the selected payment family policy.';
	}

	if ( $subscription_id <= 0 ) {
		$errors[] = 'A positive subscription ID is required.';
		return woopayments_merge_subscriptions_base_drive_facts( $subscription_id, null, null, $errors, $emails, $expected_gateway_id, $expected_token_id, $expected_token_type, $payment_family );
	}
	if ( ! function_exists( 'wcs_get_subscription' ) || ! class_exists( 'WC_Subscriptions_Manager' ) || ! class_exists( 'WC_Subscriptions_Payment_Gateways' ) ) {
		$errors[] = 'WC Subscriptions renewal classes/functions are unavailable.';
		return woopayments_merge_subscriptions_base_drive_facts( $subscription_id, null, null, $errors, $emails, $expected_gateway_id, $expected_token_id, $expected_token_type, $payment_family );
	}

	$subscription = wcs_get_subscription( $subscription_id );
	if ( ! $subscription instanceof WC_Subscription ) {
		$errors[] = 'Subscription could not be loaded.';
		return woopayments_merge_subscriptions_base_drive_facts( $subscription_id, null, null, $errors, $emails, $expected_gateway_id, $expected_token_id, $expected_token_type, $payment_family );
	}

	if ( $expected_gateway_id !== $subscription->get_payment_method() ) {
		$errors[] = "Subscription payment method is not {$expected_gateway_id}.";
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
	$facts        = woopayments_merge_subscriptions_base_drive_facts( $subscription_id, $subscription, $renewal_order, $errors, $emails, $expected_gateway_id, $expected_token_id, $expected_token_type, $payment_family );

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
 * @param string               $expected_gateway_id Expected renewal gateway ID.
 * @param int                  $expected_token_id Expected saved token ID.
 * @param string               $expected_token_type Expected saved-token type.
 * @param string               $payment_family Renewal policy family.
 * @return array
 */
function woopayments_merge_subscriptions_base_drive_facts( int $subscription_id, $subscription, $renewal_order, array $errors, array $emails, string $expected_gateway_id = 'woocommerce_payments', int $expected_token_id = 0, string $expected_token_type = 'CC', string $payment_family = 'card' ): array {
	$subscription_status = $subscription instanceof WC_Subscription ? $subscription->get_status() : '';
	$renewal_order_id    = $renewal_order instanceof WC_Order ? (int) $renewal_order->get_id() : 0;
	$renewal_order_status = $renewal_order instanceof WC_Order ? $renewal_order->get_status() : '';
	$renewal_intention_status = $renewal_order instanceof WC_Order ? (string) $renewal_order->get_meta( '_intention_status', true ) : '';
	$belongs             = $renewal_order_id > 0 ? woopayments_merge_subscriptions_renewal_belongs_to_subscription( $renewal_order_id, $subscription_id ) : false;
	$contains_exists     = function_exists( 'wcs_order_contains_renewal' );
	$contains            = $contains_exists && $renewal_order_id > 0 ? (bool) wcs_order_contains_renewal( $renewal_order_id ) : false;
	$payment_meta        = $renewal_order instanceof WC_Order ? woopayments_merge_subscriptions_meta_presence(
		$renewal_order,
		array(
			'_transaction_id',
			'_intent_id',
			'_intention_status',
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
	$token_presence   = woopayments_merge_subscriptions_token_presence( $subscription, $renewal_order, $expected_gateway_id, $expected_token_type, $expected_token_id );
	$customer_meta    = woopayments_merge_subscriptions_customer_meta_presence( $subscription );
	$evaluation        = woopayments_merge_subscriptions_evaluate_renewal_success_context(
		array(
			'subscription_id'              => $subscription_id,
			'subscription_exists'          => $subscription instanceof WC_Subscription,
			'subscription_status'          => $subscription_status,
			'subscription_payment_method'  => $subscription instanceof WC_Subscription ? $subscription->get_payment_method() : '',
			'renewal_order_id'             => $renewal_order_id,
			'renewal_order_exists'         => $renewal_order instanceof WC_Order,
			'renewal_order_status'         => $renewal_order_status,
			'renewal_order_payment_method' => $renewal_order instanceof WC_Order ? $renewal_order->get_payment_method() : '',
			'expected_renewal_gateway_id'  => $expected_gateway_id,
			'expected_token_id'            => $expected_token_id,
			'expected_token_type'          => $expected_token_type,
			'payment_family'               => $payment_family,
			'renewal_belongs_to_subscription' => $belongs,
			'wcs_order_contains_renewal_exists' => $contains_exists,
			'wcs_order_contains_renewal'   => $contains,
			'renewal_intention_status'     => $renewal_intention_status,
			'payment_meta_presence'        => $payment_meta,
			'subscription_meta_presence'   => $subscription_meta,
			'token_presence'               => $token_presence,
			'customer_meta_presence'       => $customer_meta,
			'emails'                       => $emails,
		)
	);

	return array(
		'success'                      => false,
		'mode'                         => 'drive',
		'errors'                       => array_values( $errors ),
		'success_checks_failed'        => array_values( $evaluation['success_checks_failed'] ),
		'subscription_id'              => $subscription_id,
		'subscription_status'          => $subscription_status,
		'subscription_payment_method'  => $subscription instanceof WC_Subscription ? $subscription->get_payment_method() : '',
		'renewal_order_id'             => $renewal_order_id,
		'renewal_order_status'         => $renewal_order_status,
		'renewal_order_payment_method' => $renewal_order instanceof WC_Order ? $renewal_order->get_payment_method() : '',
		'expected_renewal_gateway_id'  => $expected_gateway_id,
		'expected_token_id'            => $expected_token_id,
		'expected_token_type'          => $expected_token_type,
		'payment_family'               => $payment_family,
		'renewal_processing_model'     => $evaluation['renewal_processing_model'],
		'renewal_requires_email_evidence' => $evaluation['renewal_requires_email_evidence'],
		'async_payment_processing_accepted' => $evaluation['async_payment_processing_accepted'],
		'renewal_intention_status'     => $renewal_intention_status,
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
 * Evaluate whether the captured renewal facts satisfy this gateway's renewal policy.
 *
 * @param array $context Renewal facts.
 * @return array
 */
function woopayments_merge_subscriptions_evaluate_renewal_success_context( array $context ): array {
	$expected_gateway_id = (string) ( $context['expected_renewal_gateway_id'] ?? $context['expected_gateway_id'] ?? 'woocommerce_payments' );
	$expected_token_id   = (int) ( $context['expected_token_id'] ?? 0 );
	$payment_family      = (string) ( $context['payment_family'] ?? woopayments_merge_subscriptions_family_for_gateway( $expected_gateway_id ) );
	$expected_token_type = (string) ( $context['expected_token_type'] ?? woopayments_merge_subscriptions_token_type_for_gateway( $expected_gateway_id ) );
	$family_policy       = woopayments_merge_subscriptions_payment_family_policy( $payment_family );
	$policy              = woopayments_merge_subscriptions_renewal_success_policy( $expected_gateway_id );
	$payment_meta        = isset( $context['payment_meta_presence'] ) && is_array( $context['payment_meta_presence'] ) ? $context['payment_meta_presence'] : array();
	$subscription_meta   = isset( $context['subscription_meta_presence'] ) && is_array( $context['subscription_meta_presence'] ) ? $context['subscription_meta_presence'] : array();
	$token_presence      = isset( $context['token_presence'] ) && is_array( $context['token_presence'] ) ? $context['token_presence'] : array();
	$customer_meta       = isset( $context['customer_meta_presence'] ) && is_array( $context['customer_meta_presence'] ) ? $context['customer_meta_presence'] : array();
	$emails              = isset( $context['emails'] ) && is_array( $context['emails'] ) ? $context['emails'] : array();
	$renewal_order_id    = (int) ( $context['renewal_order_id'] ?? 0 );
	$subscription_id     = (int) ( $context['subscription_id'] ?? 0 );
	$renewal_order_exists = array_key_exists( 'renewal_order_exists', $context ) ? (bool) $context['renewal_order_exists'] : $renewal_order_id > 0;
	$subscription_exists = array_key_exists( 'subscription_exists', $context ) ? (bool) $context['subscription_exists'] : $subscription_id > 0;
	$renewal_order_status = (string) ( $context['renewal_order_status'] ?? '' );
	$subscription_status = (string) ( $context['subscription_status'] ?? '' );
	$renewal_order_payment_method = (string) ( $context['renewal_order_payment_method'] ?? '' );
	$renewal_intention_status = (string) ( $context['renewal_intention_status'] ?? '' );
	$failed_checks      = array();

	if ( null === $family_policy ) {
		$failed_checks[] = 'Payment family must be card or sepa.';
	} elseif ( $expected_gateway_id !== $family_policy['gateway_id'] || $expected_token_type !== $family_policy['token_type'] ) {
		$failed_checks[] = 'Expected gateway/token values do not match the selected payment family policy.';
	}

	if ( ! $renewal_order_exists ) {
		$failed_checks[] = 'No renewal order was captured.';
	} elseif ( ! in_array( $renewal_order_status, $policy['renewal_order_statuses'], true ) ) {
		$failed_checks[] = woopayments_merge_subscriptions_policy_order_status_failure_message( $policy );
	}

	if ( $subscription_exists && ! in_array( $subscription_status, $policy['subscription_statuses'], true ) ) {
		$failed_checks[] = woopayments_merge_subscriptions_policy_subscription_status_failure_message( $policy );
	}

	if ( $renewal_order_exists && $expected_gateway_id !== $renewal_order_payment_method ) {
		$failed_checks[] = "Renewal order payment method is not {$expected_gateway_id}.";
	}

	if ( empty( $token_presence['matching_expected_token'] ) ) {
		$failed_checks[] = "No {$expected_token_type} token for {$expected_gateway_id} was present on the subscription, renewal order, or customer token list.";
	} elseif ( $expected_token_id > 0 && empty( $token_presence['expected_token_id_matches_policy'] ) ) {
		$failed_checks[] = "Expected token {$expected_token_id} did not match the {$expected_token_type} / {$expected_gateway_id} policy.";
	}
	if ( empty( $context['renewal_belongs_to_subscription'] ) ) {
		$failed_checks[] = 'Renewal order does not belong to the subscription.';
	}
	if ( empty( $context['wcs_order_contains_renewal_exists'] ) || empty( $context['wcs_order_contains_renewal'] ) ) {
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
	if ( ! empty( $policy['intention_statuses'] ) && ! in_array( $renewal_intention_status, $policy['intention_statuses'], true ) ) {
		$failed_checks[] = 'Asynchronous renewal intent is not processing or succeeded.';
	}
	if ( ! empty( $policy['requires_email_evidence'] ) && empty( $emails ) ) {
		$failed_checks[] = 'No renewal email evidence was captured.';
	}

	return array(
		'success_checks_failed'        => array_values( $failed_checks ),
		'renewal_processing_model'     => $policy['model'],
		'renewal_requires_email_evidence' => (bool) $policy['requires_email_evidence'],
		'async_payment_processing_accepted' => 'asynchronous_processing' === $policy['model'] && empty( $failed_checks ),
		'payment_family'                 => $payment_family,
		'expected_token_type'            => $expected_token_type,
	);
}

/**
 * Get the stable gateway/token contract for a renewal family.
 *
 * @param string $payment_family Renewal policy family.
 * @return array{gateway_id:string,token_type:string}|null
 */
function woopayments_merge_subscriptions_payment_family_policy( string $payment_family ): ?array {
	if ( 'card' === $payment_family ) {
		return array(
			'gateway_id' => 'woocommerce_payments',
			'token_type' => 'CC',
		);
	}

	if ( 'sepa' === $payment_family ) {
		return array(
			'gateway_id' => 'woocommerce_payments_sepa_debit',
			'token_type' => 'wcpay_sepa',
		);
	}

	return null;
}

/**
 * Derive the renewal family from a gateway ID for legacy direct-driver callers.
 *
 * @param string $gateway_id Expected renewal gateway ID.
 * @return string
 */
function woopayments_merge_subscriptions_family_for_gateway( string $gateway_id ): string {
	return 'woocommerce_payments_sepa_debit' === $gateway_id ? 'sepa' : 'card';
}

/**
 * Derive the saved-token type from a gateway ID for legacy direct-driver callers.
 *
 * @param string $gateway_id Expected renewal gateway ID.
 * @return string
 */
function woopayments_merge_subscriptions_token_type_for_gateway( string $gateway_id ): string {
	return 'sepa' === woopayments_merge_subscriptions_family_for_gateway( $gateway_id ) ? 'wcpay_sepa' : 'CC';
}

/**
 * Get the renewal success policy for a payment method family.
 *
 * @param string $expected_gateway_id Expected gateway ID.
 * @return array
 */
function woopayments_merge_subscriptions_renewal_success_policy( string $expected_gateway_id ): array {
	if ( 'woocommerce_payments_sepa_debit' === $expected_gateway_id ) {
		return array(
			'model'                    => 'asynchronous_processing',
			'renewal_order_statuses'   => array( 'pending', 'on-hold', 'processing', 'completed' ),
			'subscription_statuses'    => array( 'on-hold', 'active' ),
			'intention_statuses'       => array( 'processing', 'succeeded' ),
			'requires_email_evidence'  => false,
		);
	}

	return array(
		'model'                    => 'synchronous_capture',
		'renewal_order_statuses'   => array( 'processing', 'completed' ),
		'subscription_statuses'    => array( 'active' ),
		'intention_statuses'       => array(),
		'requires_email_evidence'  => true,
	);
}

/**
 * Get the order-status failure message for a renewal success policy.
 *
 * @param array $policy Renewal success policy.
 * @return string
 */
function woopayments_merge_subscriptions_policy_order_status_failure_message( array $policy ): string {
	if ( 'asynchronous_processing' === ( $policy['model'] ?? '' ) ) {
		return 'Asynchronous renewal order is not pending, on-hold, processing, or completed.';
	}
	return 'Renewal order is not processing or completed.';
}

/**
 * Get the subscription-status failure message for a renewal success policy.
 *
 * @param array $policy Renewal success policy.
 * @return string
 */
function woopayments_merge_subscriptions_policy_subscription_status_failure_message( array $policy ): string {
	if ( 'asynchronous_processing' === ( $policy['model'] ?? '' ) ) {
		return 'Asynchronous subscription is not active or on-hold after renewal.';
	}
	return 'Subscription is not active after renewal.';
}

/**
 * Get the selected WooPayments gateway.
 *
 * @param string $gateway_id Expected gateway ID.
 * @return object|null
 */
function woopayments_merge_subscriptions_get_gateway( string $gateway_id = 'woocommerce_payments' ) {
	if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->payment_gateways() ) {
		return null;
	}
	$gateways = WC()->payment_gateways()->payment_gateways();
	return $gateways[ $gateway_id ] ?? null;
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
 * @param string               $expected_gateway_id Expected gateway ID.
 * @param string               $expected_token_type Expected saved-token type.
 * @param int                  $expected_token_id Exact saved-token ID, or zero.
 * @return array
 */
function woopayments_merge_subscriptions_token_presence( $subscription, $renewal_order, string $expected_gateway_id = 'woocommerce_payments', string $expected_token_type = 'CC', int $expected_token_id = 0 ): array {
	$subscription_tokens = $subscription instanceof WC_Subscription && method_exists( $subscription, 'get_payment_tokens' ) ? $subscription->get_payment_tokens() : array();
	$renewal_tokens      = $renewal_order instanceof WC_Order && method_exists( $renewal_order, 'get_payment_tokens' ) ? $renewal_order->get_payment_tokens() : array();
	$user_id             = $subscription instanceof WC_Subscription ? (int) $subscription->get_user_id() : 0;
	$customer_tokens     = $user_id > 0 && class_exists( 'WC_Payment_Tokens' ) ? WC_Payment_Tokens::get_customer_tokens( $user_id, $expected_gateway_id ) : array();
	$subscription_ids    = array_map( 'intval', (array) $subscription_tokens );
	$renewal_ids         = array_map( 'intval', (array) $renewal_tokens );
	$customer_ids        = array_map(
		static function ( $token ): int {
			return is_object( $token ) && method_exists( $token, 'get_id' ) ? (int) $token->get_id() : 0;
		},
		(array) $customer_tokens
	);
	$all_token_ids       = array_values( array_unique( array_filter( array_merge( $subscription_ids, $renewal_ids, $customer_ids ) ) ) );
	$token_facts         = array();
	$matching_token_ids  = array();

	if ( class_exists( 'WC_Payment_Tokens' ) ) {
		foreach ( $all_token_ids as $token_id ) {
			$token = WC_Payment_Tokens::get( $token_id );
			if ( ! is_object( $token ) ) {
				continue;
			}

			$token_type = method_exists( $token, 'get_type' ) ? (string) $token->get_type() : '';
			$gateway_id = method_exists( $token, 'get_gateway_id' ) ? (string) $token->get_gateway_id() : '';
			$matches    = $expected_token_type === $token_type && $expected_gateway_id === $gateway_id;
			if ( $matches ) {
				$matching_token_ids[] = (int) $token_id;
			}

			$token_facts[] = array(
				'id'         => (int) $token_id,
				'type'       => $token_type,
				'gateway_id' => $gateway_id,
				'class'      => get_class( $token ),
				'matches'    => $matches,
			);
		}
	}

	return array(
		'subscription_has_tokens' => ! empty( $subscription_tokens ),
		'renewal_has_tokens'      => ! empty( $renewal_tokens ),
		'customer_has_tokens'     => ! empty( $customer_tokens ),
		'customer_gateway_id'     => $expected_gateway_id,
		'expected_token_type'     => $expected_token_type,
		'matching_expected_token' => ! empty( $matching_token_ids ),
		'expected_token_id_matches_policy' => $expected_token_id <= 0 || in_array( $expected_token_id, $matching_token_ids, true ),
		'subscription_token_ids'  => $subscription_ids,
		'renewal_token_ids'       => $renewal_ids,
		'customer_token_ids'      => array_values( array_filter( $customer_ids ) ),
		'all_token_ids'           => $all_token_ids,
		'matching_token_ids'      => $matching_token_ids,
		'tokens'                  => $token_facts,
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
		'payment_family'                  => (string) ( $payload['payment_family'] ?? 'card' ),
		'expected_renewal_gateway_id'     => (string) ( $payload['expected_renewal_gateway_id'] ?? 'woocommerce_payments' ),
		'expected_token_type'             => (string) ( $payload['expected_token_type'] ?? 'CC' ),
		'renewal_processing_model'        => (string) ( $payload['renewal_processing_model'] ?? 'synchronous_capture' ),
		'renewal_requires_email_evidence' => (bool) ( $payload['renewal_requires_email_evidence'] ?? true ),
		'async_payment_processing_accepted' => (bool) ( $payload['async_payment_processing_accepted'] ?? false ),
		'renewal_intention_status'        => (string) ( $payload['renewal_intention_status'] ?? '' ),
		'renewal_belongs_to_subscription' => (bool) ( $payload['renewal_belongs_to_subscription'] ?? false ),
		'wcs_order_contains_renewal_exists' => (bool) ( $payload['wcs_order_contains_renewal_exists'] ?? false ),
		'wcs_order_contains_renewal'      => (bool) ( $payload['wcs_order_contains_renewal'] ?? false ),
		'payment_meta_presence'           => $payload['payment_meta_presence'] ?? array(),
		'subscription_meta_presence'      => $payload['subscription_meta_presence'] ?? array(),
		'token_presence'                  => woopayments_merge_subscriptions_normalize_token_presence( $payload['token_presence'] ?? array() ),
		'customer_meta_presence'          => $payload['customer_meta_presence'] ?? array(),
		'emails'                          => $payload['emails'] ?? array(),
	);

	woopayments_merge_subscriptions_recursive_ksort( $normalized );
	echo json_encode( $normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
}

/**
 * Keep normalized renewal comparisons stable across stores.
 *
 * Raw token IDs are useful for token-continuity gates, but reference and target
 * stores naturally allocate different IDs. The cross-store renewal diff should
 * compare token presence and gateway shape, not literal IDs.
 *
 * @param mixed $token_presence Raw token presence payload.
 * @return array
 */
function woopayments_merge_subscriptions_normalize_token_presence( $token_presence ): array {
	if ( ! is_array( $token_presence ) ) {
		return array();
	}

	return array(
		'subscription_has_tokens' => (bool) ( $token_presence['subscription_has_tokens'] ?? false ),
		'renewal_has_tokens'      => (bool) ( $token_presence['renewal_has_tokens'] ?? false ),
		'customer_has_tokens'     => (bool) ( $token_presence['customer_has_tokens'] ?? false ),
		'customer_gateway_id'     => (string) ( $token_presence['customer_gateway_id'] ?? 'woocommerce_payments' ),
		'expected_token_type'     => (string) ( $token_presence['expected_token_type'] ?? 'CC' ),
		'matching_expected_token' => (bool) ( $token_presence['matching_expected_token'] ?? false ),
		'expected_token_id_matches_policy' => (bool) ( $token_presence['expected_token_id_matches_policy'] ?? false ),
	);
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
