<?php
/**
 * Local token-continuity state driver for the WooPayments merge harness.
 *
 * This file is normally executed through `wp eval-file -` by
 * token-continuity-gate.sh. It is intentionally local-only: it checks and
 * toggles the target store between the separate WooPayments plugin and native
 * WooPayments so persisted token rows can be verified across the cutover.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsSepaToken;

$tool_args = isset( $args ) && is_array( $args ) ? $args : array_slice( $argv ?? array(), 1 );
$mode      = $tool_args[0] ?? '';

if ( 'preflight-plugin' === $mode ) {
	woopayments_merge_token_continuity_emit( woopayments_merge_token_continuity_preflight_plugin() );
	exit( 0 );
}

if ( 'prepare-source-cart' === $mode ) {
	$customer_id = isset( $tool_args[1] ) ? (int) $tool_args[1] : 0;
	woopayments_merge_token_continuity_emit( woopayments_merge_token_continuity_prepare_source_cart( $customer_id ) );
	exit( 0 );
}

if ( 'persist-source-token' === $mode ) {
	$customer_id        = isset( $tool_args[1] ) ? (int) $tool_args[1] : 0;
	$payment_method_id = isset( $tool_args[2] ) ? (string) $tool_args[2] : '';
	$mandate_id        = isset( $tool_args[3] ) ? (string) $tool_args[3] : '';
	$source_token_id   = isset( $tool_args[4] ) ? (int) $tool_args[4] : 0;
	woopayments_merge_token_continuity_emit( woopayments_merge_token_continuity_persist_source_token( $customer_id, $payment_method_id, $mandate_id, $source_token_id ) );
	exit( 0 );
}

if ( 'provision-source-token' === $mode ) {
	$customer_id = isset( $tool_args[1] ) ? (int) $tool_args[1] : 0;
	woopayments_merge_token_continuity_emit( woopayments_merge_token_continuity_provision_source_token( $customer_id ) );
	exit( 0 );
}

if ( 'cutover-native' === $mode ) {
	$token_id = isset( $tool_args[1] ) ? (int) $tool_args[1] : 0;
	woopayments_merge_token_continuity_emit( woopayments_merge_token_continuity_cutover_native( $token_id ) );
	exit( 0 );
}

if ( 'assert-native-token' === $mode ) {
	$customer_id = isset( $tool_args[1] ) ? (int) $tool_args[1] : 0;
	$token_id    = isset( $tool_args[2] ) ? (int) $tool_args[2] : 0;
	$gateway_id  = isset( $tool_args[3] ) ? (string) $tool_args[3] : '';
	$token_type  = isset( $tool_args[4] ) ? (string) $tool_args[4] : '';
	woopayments_merge_token_continuity_emit( woopayments_merge_token_continuity_assert_native_token( $customer_id, $token_id, $gateway_id, $token_type ) );
	exit( 0 );
}

if ( 'subscription-from-order' === $mode ) {
	$order_id = isset( $tool_args[1] ) ? (int) $tool_args[1] : 0;
	woopayments_merge_token_continuity_emit( woopayments_merge_token_continuity_subscription_from_order( $order_id ) );
	exit( 0 );
}

if ( 'provision-renewal-subscription' === $mode ) {
	$customer_id = isset( $tool_args[1] ) ? (int) $tool_args[1] : 0;
	$token_id    = isset( $tool_args[2] ) ? (int) $tool_args[2] : 0;
	$product_id  = isset( $tool_args[3] ) ? (int) $tool_args[3] : 0;
	$mandate_id  = isset( $tool_args[4] ) ? (string) $tool_args[4] : '';
	woopayments_merge_token_continuity_emit( woopayments_merge_token_continuity_provision_renewal_subscription( $customer_id, $token_id, $product_id, $mandate_id ) );
	exit( 0 );
}

if ( 'restore' === $mode ) {
	woopayments_merge_token_continuity_emit( woopayments_merge_token_continuity_restore_plugin() );
	exit( 0 );
}

woopayments_merge_token_continuity_emit(
	array(
		'success' => false,
		'mode'    => $mode,
		'errors'  => array( 'Unknown mode. Use preflight-plugin, prepare-source-cart, persist-source-token, assert-native-token, subscription-from-order, provision-renewal-subscription, cutover-native, or restore.' ),
	)
);
exit( 2 );

/**
 * Emit a single JSON line.
 *
 * @param array<string,mixed> $payload Payload.
 */
function woopayments_merge_token_continuity_emit( array $payload ): void {
	echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) . "\n";
}

/**
 * Ensure plugin helper functions are available.
 */
function woopayments_merge_token_continuity_load_plugin_helpers(): void {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
}

/**
 * Run the plugin-side preflight before the browser token save.
 *
 * @return array<string,mixed>
 */
function woopayments_merge_token_continuity_preflight_plugin(): array {
	woopayments_merge_token_continuity_load_plugin_helpers();

	$errors                  = array();
	$wcpay_plugin_active     = is_plugin_active( 'woocommerce-payments/woocommerce-payments.php' );
	$sepa_gateway_registered = woopayments_merge_token_continuity_gateway_registered( 'woocommerce_payments_sepa_debit' );
	$sepa_gateway_enabled    = woopayments_merge_token_continuity_gateway_enabled( 'woocommerce_payments_sepa_debit' );
	$woocommerce_active      = class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	$subscriptions_available = class_exists( 'WC_Subscriptions' ) || function_exists( 'wcs_get_subscription' );

	if ( ! $woocommerce_active ) {
		$errors[] = 'WooCommerce is not active.';
	}
	if ( ! $wcpay_plugin_active ) {
		$errors[] = 'The separate WooPayments plugin must be active before saving the source SEPA token.';
	}
	if ( ! $sepa_gateway_registered ) {
		$errors[] = 'The WooPayments SEPA gateway is not registered on the plugin-side target store.';
	}
	if ( ! $sepa_gateway_enabled ) {
		$errors[] = 'The WooPayments SEPA gateway is not enabled on the plugin-side target store.';
	}
	if ( ! $subscriptions_available ) {
		$errors[] = 'WC Subscriptions is unavailable; renewal validation cannot run.';
	}

	return array(
		'success'                 => empty( $errors ),
		'mode'                    => 'preflight-plugin',
		'errors'                  => $errors,
		'wcpay_plugin_active'     => $wcpay_plugin_active,
		'sepa_gateway_registered' => $sepa_gateway_registered,
		'sepa_gateway_enabled'    => $sepa_gateway_enabled,
		'subscriptions_available' => $subscriptions_available,
	);
}

/**
 * Clear stale cart state before the source token-save checkout.
 *
 * @param int $customer_id Customer/user ID.
 * @return array<string,mixed>
 */
function woopayments_merge_token_continuity_prepare_source_cart( int $customer_id ): array {
	$errors                       = array();
	$cart_count_before            = null;
	$cart_count_after             = null;
	$persistent_cart_keys_deleted = array();
	$billing_profile              = array(
		'billing_first_name' => 'Token',
		'billing_last_name'  => 'Continuity',
		'billing_country'    => 'DE',
		'billing_address_1'  => 'Invalidenstrasse 117',
		'billing_city'       => 'Berlin',
		'billing_postcode'   => '10115',
		'billing_phone'      => '+493012345678',
		'billing_email'      => 'token-continuity@example.test',
	);

	if ( $customer_id <= 0 ) {
		$errors[] = 'A positive customer ID is required to prepare the source cart.';
	}

	if ( empty( $errors ) ) {
		if ( function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( $customer_id );
		}

		if ( function_exists( 'wc_load_cart' ) && ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) ) {
			wc_load_cart();
		}

		if ( function_exists( 'WC' ) && WC() && WC()->cart ) {
			$cart_count_before = (int) WC()->cart->get_cart_contents_count();
			WC()->cart->empty_cart( true );
			$cart_count_after = (int) WC()->cart->get_cart_contents_count();

			if ( WC()->session && method_exists( WC()->session, 'set' ) ) {
				WC()->session->set( 'cart', array() );
				if ( method_exists( WC()->session, 'save_data' ) ) {
					WC()->session->save_data();
				}
			}
		} else {
			$errors[] = 'WooCommerce cart APIs are unavailable; source checkout cart could not be isolated.';
		}

		$user_meta = get_user_meta( $customer_id );
		foreach ( array_keys( is_array( $user_meta ) ? $user_meta : array() ) as $meta_key ) {
			if ( 0 === strpos( (string) $meta_key, '_woocommerce_persistent_cart_' ) ) {
				delete_user_meta( $customer_id, (string) $meta_key );
				$persistent_cart_keys_deleted[] = (string) $meta_key;
			}
		}

		foreach ( $billing_profile as $meta_key => $value ) {
			update_user_meta( $customer_id, $meta_key, $value );
		}

		if ( class_exists( 'WC_Customer' ) ) {
			try {
				$customer = new WC_Customer( $customer_id );
				$customer->set_billing_first_name( $billing_profile['billing_first_name'] );
				$customer->set_billing_last_name( $billing_profile['billing_last_name'] );
				$customer->set_billing_country( $billing_profile['billing_country'] );
				$customer->set_billing_address_1( $billing_profile['billing_address_1'] );
				$customer->set_billing_city( $billing_profile['billing_city'] );
				$customer->set_billing_postcode( $billing_profile['billing_postcode'] );
				$customer->set_billing_phone( $billing_profile['billing_phone'] );
				$customer->set_billing_email( $billing_profile['billing_email'] );
				$customer->save();
			} catch ( Throwable $e ) {
				$errors[] = 'Could not normalize source checkout billing profile: ' . $e->getMessage();
			}
		}
	}

	return array(
		'success'                      => empty( $errors ),
		'mode'                         => 'prepare-source-cart',
		'errors'                       => $errors,
		'customer_id'                  => $customer_id,
		'cart_count_before'            => $cart_count_before,
		'cart_count_after'             => $cart_count_after,
		'persistent_cart_keys_deleted' => $persistent_cart_keys_deleted,
		'billing_profile'              => $billing_profile,
	);
}

/**
 * Provision a provider-backed SEPA source token through a local setup intent.
 *
 * The standalone WooPayments add-payment-method UI intentionally excludes SEPA
 * because this plugin version does not mark SEPA as tokenizable. For the
 * continuity gate, the right source fixture is still provider-backed: create a
 * connected-account Stripe PaymentMethod, attach it to the WCPay customer via
 * a WooPayments setup intent against local WPCOM, then persist it through the
 * plugin token service.
 *
 * @param int $customer_id Customer/user ID.
 * @return array<string,mixed>
 */
function woopayments_merge_token_continuity_provision_source_token( int $customer_id ): array {
	$errors      = array();
	$gateway_id  = 'woocommerce_payments_sepa_debit';
	$token_type  = 'wcpay_sepa';
	$payment_method_id = '';
	$setup_intent_id = '';
	$wcpay_customer_id = '';
	$token_id    = 0;
	$user        = null;
	$customer_payment_method_ids = array();
	$source_payment_method_customer_ready = false;

	if ( $customer_id <= 0 ) {
		$errors[] = 'A positive customer ID is required to provision the source token.';
	}
	if ( ! class_exists( 'WC_Payments' ) || ! method_exists( 'WC_Payments', 'get_token_service' ) || ! method_exists( 'WC_Payments', 'get_customer_service' ) ) {
		$errors[] = 'WooPayments plugin services are unavailable.';
	}
	if ( ! class_exists( 'WC_Payment_Token_WCPay_SEPA' ) ) {
		$errors[] = 'WooPayments plugin SEPA token class is unavailable.';
	}
	if ( ! class_exists( '\WCPay\Core\Server\Request\Create_And_Confirm_Setup_Intention' ) ) {
		$errors[] = 'WooPayments setup-intent request class is unavailable.';
	}

	if ( empty( $errors ) ) {
		$user = get_user_by( 'id', $customer_id );
		if ( ! is_object( $user ) || ! isset( $user->ID ) || (int) $user->ID !== $customer_id ) {
			$errors[] = 'Source token customer could not be loaded.';
		}
	}

	if ( empty( $errors ) ) {
		try {
			$payment_method_id = woopayments_merge_token_continuity_create_connected_account_sepa_payment_method();
			$wcpay_customer_id = woopayments_merge_token_continuity_get_or_create_wcpay_customer_id( $customer_id );
			if ( '' === $wcpay_customer_id ) {
				$errors[] = 'Source customer is missing a WCPay customer ID.';
			}
		} catch ( Throwable $e ) {
			$errors[] = 'Creating provider-backed source PaymentMethod threw ' . get_class( $e ) . ': ' . $e->getMessage();
		}
	}

	if ( empty( $errors ) ) {
		try {
			$request = \WCPay\Core\Server\Request\Create_And_Confirm_Setup_Intention::create();
			$request->set_customer( $wcpay_customer_id );
			$request->set_payment_method( $payment_method_id );
			$request->set_payment_method_types( array( 'sepa_debit' ) );
			$request->set_mandate_data(
				array(
					'customer_acceptance' => array(
						'type'   => 'online',
						'online' => array(
							'ip_address' => '127.0.0.1',
							'user_agent' => 'WooPayments merge local token-continuity fixture',
						),
					),
				)
			);
			$request->assign_hook( 'woopayments_merge_token_continuity_create_setup_intention_request' );
			$setup_intent = $request->send();
			$setup_intent_id = is_object( $setup_intent ) && method_exists( $setup_intent, 'get_id' ) ? (string) $setup_intent->get_id() : '';
			$status = is_object( $setup_intent ) && method_exists( $setup_intent, 'get_status' ) ? (string) $setup_intent->get_status() : '';
			if ( 'succeeded' !== $status ) {
				$errors[] = 'Provider setup intent did not succeed.';
			}
			if ( is_object( $setup_intent ) && method_exists( $setup_intent, 'get_payment_method_id' ) ) {
				$intent_payment_method_id = (string) $setup_intent->get_payment_method_id();
				if ( '' !== $intent_payment_method_id ) {
					$payment_method_id = $intent_payment_method_id;
				}
			}
		} catch ( Throwable $e ) {
			$errors[] = 'Creating provider setup intent threw ' . get_class( $e ) . ': ' . $e->getMessage();
		}
	}

	if ( empty( $errors ) ) {
		$source_readiness = woopayments_merge_token_continuity_assert_source_payment_method_customer_ready( $customer_id, $payment_method_id );
		$customer_payment_method_ids = is_array( $source_readiness['customer_payment_method_ids'] ) ? $source_readiness['customer_payment_method_ids'] : array();
		$source_payment_method_customer_ready = (bool) $source_readiness['source_payment_method_customer_ready'];
		foreach ( $source_readiness['errors'] as $source_error ) {
			$errors[] = $source_error;
		}
	}

	if ( empty( $errors ) ) {
		try {
			$token = WC_Payments::get_token_service()->add_payment_method_to_user( $payment_method_id, $user );
			if ( $token instanceof WC_Payment_Token ) {
				$token_id = (int) $token->get_id();
			} else {
				$errors[] = 'WooPayments plugin token service did not return a WC payment token.';
			}
		} catch ( Throwable $e ) {
			$errors[] = 'Persisting provider-backed source token threw ' . get_class( $e ) . ': ' . $e->getMessage();
		}
	}

	if ( empty( $errors ) ) {
		$raw_token = $token_id > 0 ? woopayments_merge_token_continuity_get_raw_source_token( $token_id ) : null;
		if ( ! is_array( $raw_token ) ) {
			$errors[] = 'Provider-backed source token row could not be loaded from storage.';
		} else {
			if ( $customer_id !== (int) $raw_token['user_id'] ) {
				$errors[] = 'Provider-backed source token does not belong to the expected customer.';
			}
			if ( $gateway_id !== (string) $raw_token['gateway_id'] ) {
				$errors[] = 'Provider-backed source token gateway is not woocommerce_payments_sepa_debit.';
			}
			if ( $payment_method_id !== (string) $raw_token['token'] ) {
				$errors[] = 'Provider-backed source token does not reference the setup-intent PaymentMethod.';
			}
			if ( $token_type !== strtolower( (string) $raw_token['type'] ) ) {
				$errors[] = 'Provider-backed source token type is not wcpay_sepa.';
			}
		}
	}

	return array(
		'success'           => empty( $errors ),
		'mode'              => 'provision-source-token',
		'errors'            => $errors,
		'customer_id'       => $customer_id,
		'wcpay_customer_id' => $wcpay_customer_id,
		'payment_method_id' => $payment_method_id,
		'setup_intent_id'   => $setup_intent_id,
		'mandate_id'        => '',
		'source_payment_method_customer_ready' => $source_payment_method_customer_ready,
		'customer_payment_method_ids' => $customer_payment_method_ids,
		'token_id'          => $token_id,
		'gateway_id'        => $gateway_id,
		'token_type'        => $token_type,
		'created'           => true,
		'reused'            => false,
		'persistence_strategy' => 'provider_setup_intent',
	);
}

/**
 * Persist the plugin-era source token for a browser-created Stripe PaymentMethod.
 *
 * SEPA checkout can create a real PaymentMethod without exposing a save-token
 * checkbox. The plugin token service is tried first so the source fixture can
 * use canonical payment-method details, then the persisted legacy token row is
 * validated directly because the separate plugin runtime cannot reliably load
 * its custom wcpay_sepa token type through WC_Payment_Tokens::get().
 *
 * @param int    $customer_id Customer/user ID.
 * @param string $payment_method_id Stripe PaymentMethod ID, when observed directly by the browser.
 * @param string $mandate_id Stripe mandate ID from the source PaymentIntent.
 * @param int    $source_token_id Browser-visible WooCommerce token ID, when the plugin created one.
 * @return array<string,mixed>
 */
function woopayments_merge_token_continuity_persist_source_token( int $customer_id, string $payment_method_id, string $mandate_id, int $source_token_id = 0 ): array {
	$errors      = array();
	$gateway_id  = 'woocommerce_payments_sepa_debit';
	$token_type  = 'wcpay_sepa';
	$token       = null;
	$token_id    = 0;
	$created     = false;
	$reused      = false;
	$strategy    = '';
	$last4       = '';
	$user        = null;
	$wcpay_customer_id = '';
	$customer_payment_method_ids = array();
	$source_payment_method_customer_ready = false;
	$browser_raw_token = null;

	if ( $customer_id <= 0 ) {
		$errors[] = 'A positive customer ID is required to persist the source token.';
	}
	if ( '' !== $mandate_id && ! preg_match( '/^mandate_[A-Za-z0-9]{8,}$/', $mandate_id ) ) {
		$errors[] = 'Source token mandate ID is not a Stripe mandate ID.';
	}
	if ( $source_token_id < 0 ) {
		$errors[] = 'Source token ID cannot be negative.';
	}
	if ( ! class_exists( 'WC_Payment_Token' ) ) {
		$errors[] = 'WooCommerce payment token API is unavailable.';
	}
	if ( ! class_exists( 'WC_Payments' ) || ! method_exists( 'WC_Payments', 'get_token_service' ) ) {
		$errors[] = 'WooPayments plugin token service is unavailable.';
	}
	if ( ! class_exists( 'WC_Payment_Token_WCPay_SEPA' ) ) {
		$errors[] = 'WooPayments plugin SEPA token class is unavailable.';
	}

	if ( empty( $errors ) ) {
		$user = get_user_by( 'id', $customer_id );
		if ( ! is_object( $user ) || ! isset( $user->ID ) || (int) $user->ID !== $customer_id ) {
			$errors[] = 'Source token customer could not be loaded.';
		}
	}

	if ( empty( $errors ) && $source_token_id > 0 ) {
		$browser_raw_token = woopayments_merge_token_continuity_get_raw_source_token( $source_token_id );
		if ( ! is_array( $browser_raw_token ) ) {
			$errors[] = 'Browser-reported source token row could not be loaded from storage.';
		} else {
			if ( $customer_id !== (int) $browser_raw_token['user_id'] ) {
				$errors[] = 'Browser-reported source token row does not belong to the expected customer.';
			}
			if ( $gateway_id !== (string) $browser_raw_token['gateway_id'] ) {
				$errors[] = 'Browser-reported source token row is not a WooPayments SEPA token.';
			}
			if ( $token_type !== strtolower( (string) $browser_raw_token['type'] ) ) {
				$errors[] = 'Browser-reported source token row type is not wcpay_sepa.';
			}
			if ( '' === $payment_method_id ) {
				$payment_method_id = (string) $browser_raw_token['token'];
			} elseif ( $payment_method_id !== (string) $browser_raw_token['token'] ) {
				$errors[] = 'Browser-reported source token row does not reference the observed PaymentMethod.';
			}
			if ( empty( $errors ) ) {
				$token_id = $source_token_id;
				$reused   = true;
				$strategy = 'browser_token_row';
			}
		}
	}

	if ( ! preg_match( '/^pm_[A-Za-z0-9]{8,}$/', $payment_method_id ) ) {
		$errors[] = 'A Stripe PaymentMethod ID is required to persist the source token.';
	}

	if ( empty( $errors ) ) {
		$source_readiness = woopayments_merge_token_continuity_assert_source_payment_method_customer_ready( $customer_id, $payment_method_id );
		$wcpay_customer_id = (string) $source_readiness['wcpay_customer_id'];
		$customer_payment_method_ids = is_array( $source_readiness['customer_payment_method_ids'] ) ? $source_readiness['customer_payment_method_ids'] : array();
		$source_payment_method_customer_ready = (bool) $source_readiness['source_payment_method_customer_ready'];
		foreach ( $source_readiness['errors'] as $source_error ) {
			$errors[] = $source_error;
		}
	}

	if ( empty( $errors ) && $token_id <= 0 ) {
		$raw_token = woopayments_merge_token_continuity_get_raw_source_token_by_payment_method( $customer_id, $payment_method_id, $gateway_id );
		if ( is_array( $raw_token ) ) {
			$token_id = (int) $raw_token['token_id'];
			$reused = true;
			$strategy = 'existing_raw_token_row';
		} else {
			try {
				$token_service = WC_Payments::get_token_service();
				if ( ! is_object( $token_service ) || ! method_exists( $token_service, 'add_payment_method_to_user' ) ) {
					$errors[] = 'WooPayments plugin token service cannot add payment methods to users.';
				} else {
					$token   = $token_service->add_payment_method_to_user( $payment_method_id, $user );
					$created = true;
					$strategy = 'plugin_token_service';
					if ( $token instanceof WC_Payment_Token && method_exists( $token, 'get_last4' ) ) {
						$last4 = (string) $token->get_last4( 'edit' );
					}
				}
			} catch ( Throwable $e ) {
				$errors[] = 'Persisting source token threw ' . get_class( $e ) . ': ' . $e->getMessage();
			}
		}
	}

	if ( empty( $errors ) && ! $reused ) {
		if ( $token instanceof WC_Payment_Token ) {
			$token_id = (int) $token->get_id();
		} else {
			$errors[] = 'WooPayments plugin token service did not return a WC payment token.';
		}

		$raw_token = $token_id > 0 ? woopayments_merge_token_continuity_get_raw_source_token( $token_id ) : null;
		if ( ! is_array( $raw_token ) ) {
			$token_id = woopayments_merge_token_continuity_insert_raw_source_token( $customer_id, $payment_method_id, '' !== $last4 ? $last4 : '3000' );
			$strategy = 'raw_legacy_token_row';
		}
	}

	if ( empty( $errors ) ) {
		$raw_token = $token_id > 0 ? woopayments_merge_token_continuity_get_raw_source_token( $token_id ) : null;
		if ( ! is_array( $raw_token ) ) {
			$errors[] = 'Persisted source token row could not be loaded from storage.';
		} else {
			if ( $customer_id !== (int) $raw_token['user_id'] ) {
				$errors[] = 'Persisted source token does not belong to the expected customer.';
			}
			if ( $gateway_id !== (string) $raw_token['gateway_id'] ) {
				$errors[] = 'Persisted source token gateway is not woocommerce_payments_sepa_debit.';
			}
			if ( $payment_method_id !== (string) $raw_token['token'] ) {
				$errors[] = 'Persisted source token does not reference the browser-created PaymentMethod.';
			}
			if ( $token_type !== strtolower( (string) $raw_token['type'] ) ) {
				$errors[] = 'Persisted source token type is not wcpay_sepa.';
			}
		}
	}

	if ( empty( $errors ) && $token_id > 0 && '' !== $mandate_id ) {
		update_metadata( 'payment_token', $token_id, '_stripe_mandate_id', $mandate_id );
	}

	return array(
		'success'           => empty( $errors ),
		'mode'              => 'persist-source-token',
		'errors'            => $errors,
		'customer_id'       => $customer_id,
		'wcpay_customer_id' => $wcpay_customer_id,
		'payment_method_id' => $payment_method_id,
		'mandate_id'        => $mandate_id,
		'source_token_id'   => $source_token_id,
		'source_payment_method_customer_ready' => $source_payment_method_customer_ready,
		'customer_payment_method_ids' => $customer_payment_method_ids,
		'token_id'          => $token_id,
		'gateway_id'        => $gateway_id,
		'token_type'        => $token_type,
		'created'           => $created,
		'reused'            => $reused,
		'persistence_strategy' => $strategy,
	);
}

/**
 * Assert that the browser-created source PaymentMethod is reusable for the WCPay customer.
 *
 * @param int    $customer_id WordPress user ID.
 * @param string $payment_method_id Stripe PaymentMethod ID.
 * @return array<string,mixed>
 */
function woopayments_merge_token_continuity_assert_source_payment_method_customer_ready( int $customer_id, string $payment_method_id ): array {
	$errors = array();
	$customer_payment_method_ids = array();
	$wcpay_customer_id = woopayments_merge_token_continuity_get_wcpay_customer_id( $customer_id );

	if ( '' === $wcpay_customer_id ) {
		$errors[] = 'Source customer is missing a WCPay customer ID.';
	}
	if ( ! class_exists( 'WC_Payments' ) || ! method_exists( 'WC_Payments', 'get_payments_api_client' ) ) {
		$errors[] = 'WooPayments plugin API client is unavailable.';
	}

	if ( empty( $errors ) ) {
		try {
			$api_client = WC_Payments::get_payments_api_client();
			if ( ! is_object( $api_client ) || ! method_exists( $api_client, 'get_payment_methods' ) ) {
				$errors[] = 'WooPayments plugin API client cannot list customer payment methods.';
			} else {
				$response = $api_client->get_payment_methods( $wcpay_customer_id, 'sepa_debit' );
				$methods = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
				foreach ( $methods as $method ) {
					if ( is_array( $method ) && isset( $method['id'] ) && is_string( $method['id'] ) && '' !== $method['id'] ) {
						$customer_payment_method_ids[] = $method['id'];
					}
				}
				if ( ! in_array( $payment_method_id, $customer_payment_method_ids, true ) ) {
					$errors[] = 'Source PaymentMethod is not reusable for the WCPay customer.';
				}
			}
		} catch ( Throwable $e ) {
			$errors[] = 'Checking source PaymentMethod customer readiness threw ' . get_class( $e ) . ': ' . $e->getMessage();
		}
	}

	return array(
		'wcpay_customer_id' => $wcpay_customer_id,
		'customer_payment_method_ids' => $customer_payment_method_ids,
		'source_payment_method_customer_ready' => empty( $errors ),
		'errors' => $errors,
	);
}

/**
 * Create a SEPA PaymentMethod on the connected account using the local Test Lab Stripe key.
 *
 * @return string Stripe PaymentMethod ID.
 * @throws Exception If local Stripe prerequisites or creation fail.
 */
function woopayments_merge_token_continuity_create_connected_account_sepa_payment_method(): string {
	$secret_key = woopayments_merge_token_continuity_get_stripe_test_secret_key();
	$account_id = woopayments_merge_token_continuity_get_connected_account_id();

	$response = wp_remote_post(
		'https://api.stripe.com/v1/payment_methods',
		array(
			'timeout' => 30,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $secret_key,
				'Stripe-Account' => $account_id,
			),
			'body'    => array(
				'type'                   => 'sepa_debit',
				'sepa_debit[iban]'       => 'DE89370400440532013000',
				'billing_details[name]'  => 'Token Continuity',
				'billing_details[email]' => 'token-continuity@example.test',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		throw new Exception( 'Stripe PaymentMethod creation failed: ' . $response->get_error_message() );
	}

	$body = (string) wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );
	if ( ! is_array( $data ) || empty( $data['id'] ) || ! is_string( $data['id'] ) ) {
		throw new Exception( 'Stripe PaymentMethod creation returned no PaymentMethod id: ' . substr( preg_replace( '/\s+/', ' ', $body ), 0, 300 ) );
	}

	return $data['id'];
}

/**
 * Get the configured local Test Lab Stripe secret key without exposing it.
 *
 * @return string Stripe test secret key.
 * @throws Exception If no local test key is configured.
 */
function woopayments_merge_token_continuity_get_stripe_test_secret_key(): string {
	$secret_key = getenv( 'STRIPE_TEST_SECRET_KEY' );
	if ( ! is_string( $secret_key ) || '' === $secret_key ) {
		$secret_key = get_option( 'wcpay_test_lab_stripe_key', '' );
	}

	if ( ! is_string( $secret_key ) || 0 !== strpos( $secret_key, 'sk_test_' ) ) {
		throw new Exception( 'Stripe test secret key is not configured for the local Test Lab.' );
	}

	return $secret_key;
}

/**
 * Get the connected WooPayments account ID from the local account cache.
 *
 * @return string Connected account ID.
 * @throws Exception If the connected account ID is missing.
 */
function woopayments_merge_token_continuity_get_connected_account_id(): string {
	$account_cache = get_option( 'wcpay_account_data', array() );
	$account_id    = is_array( $account_cache ) && isset( $account_cache['data']['account_id'] ) && is_scalar( $account_cache['data']['account_id'] )
		? (string) $account_cache['data']['account_id']
		: '';

	if ( '' === $account_id ) {
		throw new Exception( 'Connected WooPayments account ID is missing from wcpay_account_data.' );
	}

	return $account_id;
}

/**
 * Get or create the WCPay customer ID for a WordPress user.
 *
 * @param int $customer_id WordPress user ID.
 * @return string WCPay customer ID.
 */
function woopayments_merge_token_continuity_get_or_create_wcpay_customer_id( int $customer_id ): string {
	$wcpay_customer_id = woopayments_merge_token_continuity_get_wcpay_customer_id( $customer_id );
	if ( '' !== $wcpay_customer_id ) {
		return $wcpay_customer_id;
	}

	$user = get_user_by( 'id', $customer_id );
	if ( ! is_object( $user ) || ! isset( $user->ID ) ) {
		return '';
	}

	$customer_service = WC_Payments::get_customer_service();
	if ( ! is_object( $customer_service ) || ! method_exists( $customer_service, 'create_customer_for_user' ) ) {
		return '';
	}

	$customer_data = class_exists( 'WC_Payments_Customer_Service' ) && method_exists( 'WC_Payments_Customer_Service', 'map_customer_data' ) && class_exists( 'WC_Customer' )
		? WC_Payments_Customer_Service::map_customer_data( null, new WC_Customer( $customer_id ) )
		: array();

	try {
		return (string) $customer_service->create_customer_for_user( $user, $customer_data );
	} catch ( Throwable $e ) {
		return '';
	}
}

/**
 * Find an existing source token row by provider PaymentMethod ID.
 *
 * @param int    $customer_id Customer/user ID.
 * @param string $payment_method_id Stripe PaymentMethod ID.
 * @param string $gateway_id WooCommerce gateway ID.
 * @return array<string,mixed>|null
 */
function woopayments_merge_token_continuity_get_raw_source_token_by_payment_method( int $customer_id, string $payment_method_id, string $gateway_id ): ?array {
	global $wpdb;

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT token_id, gateway_id, token, type, user_id FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE user_id = %d AND gateway_id = %s AND token = %s LIMIT 1",
			$customer_id,
			$gateway_id,
			$payment_method_id
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

/**
 * Load a raw source token row by token ID.
 *
 * @param int $token_id Payment token ID.
 * @return array<string,mixed>|null
 */
function woopayments_merge_token_continuity_get_raw_source_token( int $token_id ): ?array {
	global $wpdb;

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT token_id, gateway_id, token, type, user_id FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE token_id = %d LIMIT 1",
			$token_id
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

/**
 * Insert a plugin-era SEPA token row directly.
 *
 * @param int    $customer_id Customer/user ID.
 * @param string $payment_method_id Stripe PaymentMethod ID.
 * @param string $last4 IBAN last four.
 * @return int Payment token ID, or 0 on failure.
 */
function woopayments_merge_token_continuity_insert_raw_source_token( int $customer_id, string $payment_method_id, string $last4 ): int {
	global $wpdb;

	$inserted = $wpdb->insert(
		$wpdb->prefix . 'woocommerce_payment_tokens',
		array(
			'gateway_id' => 'woocommerce_payments_sepa_debit',
			'token'      => $payment_method_id,
			'user_id'    => $customer_id,
			'type'       => 'wcpay_sepa',
			'is_default' => 0,
		),
		array( '%s', '%s', '%d', '%s', '%d' )
	);
	if ( false === $inserted ) {
		return 0;
	}

	$token_id = (int) $wpdb->insert_id;
	add_metadata( 'payment_token', $token_id, 'last4', '' !== $last4 ? $last4 : '3000', true );

	return $token_id;
}

/**
 * Cut the target store over to native WooPayments.
 *
 * @param int $token_id Saved token ID.
 * @return array<string,mixed>
 */
function woopayments_merge_token_continuity_cutover_native( int $token_id ): array {
	woopayments_merge_token_continuity_load_plugin_helpers();

	$errors            = array();
	$plugin_file       = 'woocommerce-payments/woocommerce-payments.php';
	$was_plugin_active = is_plugin_active( $plugin_file );

	if ( $token_id <= 0 ) {
		$errors[] = 'Saved token ID must be positive before cutover.';
	}

	if ( $was_plugin_active ) {
		deactivate_plugins( $plugin_file, true );
	}

	$is_plugin_active = is_plugin_active( $plugin_file );
	if ( $is_plugin_active ) {
		$errors[] = 'The separate WooPayments plugin is still active after cutover.';
	}
	if ( ! class_exists( NativeWooPaymentsGateway::class ) ) {
		$errors[] = 'Native WooPayments gateway class is unavailable after cutover.';
	}
	if ( ! class_exists( WooPaymentsSepaToken::class ) ) {
		$errors[] = 'Native WooPayments SEPA token class is unavailable after cutover.';
	}

	return array(
		'success'                           => empty( $errors ),
		'mode'                              => 'cutover-native',
		'errors'                            => $errors,
		'token_id'                          => $token_id,
		'was_plugin_active'                 => $was_plugin_active,
		'wcpay_plugin_active'               => $is_plugin_active,
		'native_gateway_class_available'    => class_exists( NativeWooPaymentsGateway::class ),
		'native_sepa_token_class_available' => class_exists( WooPaymentsSepaToken::class ),
	);
}

/**
 * Assert native WooPayments can load the persisted token after cutover.
 *
 * @param int    $customer_id Customer/user ID.
 * @param int    $token_id Payment token ID.
 * @param string $gateway_id Expected gateway ID.
 * @param string $token_type Expected token type.
 * @return array<string,mixed>
 */
function woopayments_merge_token_continuity_assert_native_token( int $customer_id, int $token_id, string $gateway_id, string $token_type ): array {
	$errors      = array();
	$token       = null;
	$token_class = '';

	if ( $customer_id <= 0 ) {
		$errors[] = 'A positive customer ID is required for native token assertion.';
	}
	if ( $token_id <= 0 ) {
		$errors[] = 'A positive saved token ID is required for native token assertion.';
	}
	if ( '' === $gateway_id ) {
		$errors[] = 'Expected gateway ID is required for native token assertion.';
	}
	if ( '' === $token_type ) {
		$errors[] = 'Expected token type is required for native token assertion.';
	}
	if ( ! class_exists( 'WC_Payment_Tokens' ) || ! class_exists( 'WC_Payment_Token' ) ) {
		$errors[] = 'WooCommerce payment token APIs are unavailable.';
	}

	if ( empty( $errors ) ) {
		$token = WC_Payment_Tokens::get( $token_id );
		if ( ! $token instanceof WC_Payment_Token ) {
			$errors[] = "saved token $token_id is missing from native token storage";
		} else {
			$token_class = get_class( $token );
			if ( $customer_id !== (int) $token->get_user_id() ) {
				$errors[] = 'Native token does not belong to the expected customer.';
			}
			if ( $gateway_id !== $token->get_gateway_id() ) {
				$errors[] = 'Native token gateway does not match the expected gateway.';
			}
			if ( $token_type !== strtolower( (string) $token->get_type() ) ) {
				$errors[] = 'Native token type does not match the expected type.';
			}
			if ( 'wcpay_sepa' === $token_type && ! $token instanceof WooPaymentsSepaToken ) {
				$errors[] = 'Native SEPA token was not loaded through the native WooPayments token class map.';
			}
			if ( '' === (string) $token->get_token() ) {
				$errors[] = 'Native token is missing its provider PaymentMethod ID.';
			}
		}
	}

	return array(
		'success'     => empty( $errors ),
		'mode'        => 'assert-native-token',
		'errors'      => $errors,
		'customer_id' => $customer_id,
		'token_id'    => $token_id,
		'gateway_id'  => $gateway_id,
		'token_type'  => $token_type,
		'token_class' => $token_class,
	);
}

/**
 * Resolve the subscription created by a plugin-side checkout order.
 *
 * @param int $order_id Checkout order ID.
 * @return array<string,mixed>
 */
function woopayments_merge_token_continuity_subscription_from_order( int $order_id ): array {
	$errors          = array();
	$subscription_id = 0;

	if ( $order_id <= 0 ) {
		$errors[] = 'A positive checkout order ID is required to resolve the subscription.';
	}
	if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
		$errors[] = 'WC Subscriptions order lookup is unavailable.';
	}

	if ( empty( $errors ) ) {
		$subscriptions = wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => 'parent' ) );
		if ( empty( $subscriptions ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order_id );
		}
		foreach ( $subscriptions as $subscription ) {
			if ( is_object( $subscription ) && method_exists( $subscription, 'get_id' ) ) {
				$subscription_id = (int) $subscription->get_id();
				break;
			}
		}

		if ( $subscription_id <= 0 ) {
			$errors[] = 'No subscription could be resolved from the checkout order.';
		}
	}

	return array(
		'success'         => empty( $errors ),
		'mode'            => 'subscription-from-order',
		'errors'          => $errors,
		'order_id'        => $order_id,
		'subscription_id' => $subscription_id,
	);
}

/**
 * Provision a renewal subscription fixture from a persisted source token row.
 *
 * The source token row intentionally remains a plugin-era artifact until the
 * cutover. The separate plugin runtime cannot reliably instantiate its custom
 * wcpay_sepa type by ID, so this state leg binds the raw token ID to the
 * subscription metadata and lets the native runtime prove class mapping later.
 *
 * @param int $customer_id Customer/user ID.
 * @param int $token_id Saved payment token ID.
 * @param int $product_id Subscription product ID.
 * @param string $mandate_id Stripe mandate ID from the source PaymentIntent.
 * @return array<string,mixed>
 */
function woopayments_merge_token_continuity_provision_renewal_subscription( int $customer_id, int $token_id, int $product_id, string $mandate_id ): array {
	$errors          = array();
	$subscription_id = 0;
	$parent_order_id = 0;
	$gateway_id      = 'woocommerce_payments_sepa_debit';
	$source_token    = null;
	$payment_method_id = '';
	$product         = null;

	if ( $customer_id <= 0 ) {
		$errors[] = 'A positive customer ID is required to provision a renewal subscription.';
	}
	if ( $token_id <= 0 ) {
		$errors[] = 'A positive saved token ID is required to provision a renewal subscription.';
	}
	if ( $product_id <= 0 ) {
		$errors[] = 'A positive subscription product ID is required to provision a renewal subscription.';
	}
	if ( '' === $mandate_id && function_exists( 'get_metadata' ) && $token_id > 0 ) {
		$mandate_id = (string) get_metadata( 'payment_token', $token_id, '_stripe_mandate_id', true );
	}
	if ( '' !== $mandate_id && ! preg_match( '/^mandate_[A-Za-z0-9]{8,}$/', $mandate_id ) ) {
		$errors[] = 'Renewal fixture mandate ID is not a Stripe mandate ID.';
	}
	if ( ! function_exists( 'wcs_create_subscription' ) || ! class_exists( 'WC_Subscription' ) ) {
		$errors[] = 'WC Subscriptions creation APIs are unavailable.';
	}
	if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_create_order' ) ) {
		$errors[] = 'WooCommerce order/product APIs are unavailable.';
	}

	if ( empty( $errors ) ) {
		$source_token = woopayments_merge_token_continuity_get_raw_source_token( $token_id );
		if ( ! is_array( $source_token ) ) {
			$errors[] = 'Saved token row could not be loaded.';
		} else {
			if ( (int) $source_token['user_id'] !== $customer_id ) {
				$errors[] = 'Saved token does not belong to the expected customer.';
			}
			if ( $gateway_id !== (string) $source_token['gateway_id'] ) {
				$errors[] = 'Saved token gateway is not woocommerce_payments_sepa_debit.';
			}
			if ( 'wcpay_sepa' !== strtolower( (string) $source_token['type'] ) ) {
				$errors[] = 'Saved token type is not wcpay_sepa.';
			}
			$payment_method_id = (string) $source_token['token'];
			if ( '' === $payment_method_id ) {
				$errors[] = 'Saved token is missing its provider payment method ID.';
			}
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			$errors[] = 'Subscription product could not be loaded.';
		} elseif ( class_exists( 'WC_Subscriptions_Product' ) && ! WC_Subscriptions_Product::is_subscription( $product ) ) {
			$errors[] = 'Renewal fixture product must be a subscription product.';
		}
	}

	if ( empty( $errors ) && is_array( $source_token ) && $product instanceof WC_Product ) {
		$remote_addr_was_set = array_key_exists( 'REMOTE_ADDR', $_SERVER );
		$remote_addr         = $remote_addr_was_set ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null;
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		}

		try {
			$parent_order = wc_create_order( array( 'customer_id' => $customer_id ) );
			if ( is_wp_error( $parent_order ) ) {
				$errors[] = 'Parent order creation failed: ' . $parent_order->get_error_message();
			} elseif ( ! $parent_order instanceof WC_Order ) {
				$errors[] = 'Parent order creation did not return a WC_Order.';
			} else {
				$parent_order->add_product( $product, 1 );
				$parent_order->set_customer_id( $customer_id );
				$parent_order->set_payment_method( $gateway_id );
				$parent_order->set_payment_method_title( 'SEPA Direct Debit' );
				woopayments_merge_token_continuity_bind_token_id_to_order( $parent_order, $token_id );
				$parent_order->update_meta_data( '_payment_method_id', $payment_method_id );
				if ( '' !== $mandate_id ) {
					$parent_order->update_meta_data( '_stripe_mandate_id', $mandate_id );
				}
				woopayments_merge_token_continuity_seed_customer_meta( $parent_order, $customer_id );
				$parent_order->calculate_totals();
				$parent_order->update_status( 'completed', 'Parent order for WooPayments token-continuity renewal fixture.' );
				$parent_order->save();
				$parent_order_id = (int) $parent_order->get_id();

				$start_date   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
				$subscription = wcs_create_subscription(
					array(
						'order_id'         => $parent_order_id,
						'customer_id'      => $customer_id,
						'billing_period'   => 'month',
						'billing_interval' => 1,
						'start_date'       => $start_date,
						'date_created'     => $start_date,
						'created_via'      => 'woopayments-token-continuity-gate',
					)
				);

				if ( is_wp_error( $subscription ) ) {
					$errors[] = 'Subscription creation failed: ' . $subscription->get_error_message();
				} elseif ( ! $subscription instanceof WC_Subscription ) {
					$errors[] = 'Subscription creation did not return a WC_Subscription.';
				} else {
					$subscription->add_product( $product, 1 );
					$subscription->set_customer_id( $customer_id );
					$subscription->set_payment_method( $gateway_id );
					$subscription->set_payment_method_title( 'SEPA Direct Debit' );
					woopayments_merge_token_continuity_bind_token_id_to_order( $subscription, $token_id );
					$subscription->update_meta_data( '_payment_method_id', $payment_method_id );
					if ( '' !== $mandate_id ) {
						$subscription->update_meta_data( '_stripe_mandate_id', $mandate_id );
					}
					woopayments_merge_token_continuity_seed_customer_meta( $subscription, $customer_id );
					$subscription->calculate_totals();
					$subscription->update_status( 'active', 'Provisioned by WooPayments token-continuity gate.' );
					$subscription->update_dates(
						array(
							'next_payment' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
						)
					);
					$subscription->save();
					$subscription_id = (int) $subscription->get_id();
				}
			}
		} catch ( Throwable $e ) {
			$errors[] = 'Provisioning renewal subscription threw ' . get_class( $e ) . ': ' . $e->getMessage();
		}

		if ( $remote_addr_was_set ) {
			$_SERVER['REMOTE_ADDR'] = $remote_addr;
		} else {
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	return array(
		'success'         => empty( $errors ),
		'mode'            => 'provision-renewal-subscription',
		'errors'          => $errors,
		'customer_id'     => $customer_id,
		'token_id'        => $token_id,
		'product_id'      => $product_id,
		'gateway_id'      => $gateway_id,
		'payment_method_id' => $payment_method_id,
		'mandate_id'      => $mandate_id,
		'parent_order_id' => $parent_order_id,
		'subscription_id' => $subscription_id,
	);
}

/**
 * Bind a persisted payment token ID to an order-like object.
 *
 * @param WC_Order $order Order or subscription.
 * @param int      $token_id Payment token ID.
 */
function woopayments_merge_token_continuity_bind_token_id_to_order( WC_Order $order, int $token_id ): void {
	$order->update_meta_data( '_payment_tokens', array( $token_id ) );
}

/**
 * Get the WCPay customer ID saved for a WordPress user.
 *
 * @param int $customer_id Customer/user ID.
 * @return string WCPay customer ID, or empty when unavailable.
 */
function woopayments_merge_token_continuity_get_wcpay_customer_id( int $customer_id ): string {
	$customer_keys = array( '_wcpay_customer_id_test', '_wcpay_customer_id_live', '_wcpay_customer_id' );
	foreach ( $customer_keys as $key ) {
		$value = (string) get_user_option( $key, $customer_id );
		if ( '' === $value ) {
			$value = (string) get_user_meta( $customer_id, $key, true );
		}
		if ( '' !== $value ) {
			return $value;
		}
	}

	return '';
}

/**
 * Seed customer meta on an order-like object from the saved token owner.
 *
 * @param WC_Order $order Order or subscription.
 * @param int      $customer_id Customer/user ID.
 */
function woopayments_merge_token_continuity_seed_customer_meta( WC_Order $order, int $customer_id ): void {
	$wcpay_customer_id = woopayments_merge_token_continuity_get_wcpay_customer_id( $customer_id );
	if ( '' !== $wcpay_customer_id ) {
		$order->update_meta_data( '_stripe_customer_id', $wcpay_customer_id );
	}
}

/**
 * Restore the separate WooPayments plugin after the gate.
 *
 * @return array<string,mixed>
 */
function woopayments_merge_token_continuity_restore_plugin(): array {
	woopayments_merge_token_continuity_load_plugin_helpers();

	$errors      = array();
	$plugin_file = 'woocommerce-payments/woocommerce-payments.php';

	if ( ! is_plugin_active( $plugin_file ) && file_exists( WP_PLUGIN_DIR . '/woocommerce-payments/woocommerce-payments.php' ) ) {
		$result = activate_plugin( $plugin_file, '', false, true );
		if ( is_wp_error( $result ) ) {
			$errors[] = 'Could not restore WooPayments plugin: ' . $result->get_error_message();
		}
	}

	return array(
		'success'             => empty( $errors ),
		'mode'                => 'restore',
		'errors'              => $errors,
		'wcpay_plugin_active' => is_plugin_active( $plugin_file ),
	);
}

/**
 * Check if a payment gateway is registered.
 *
 * @param string $gateway_id Gateway ID.
 * @return bool
 */
function woopayments_merge_token_continuity_gateway_registered( string $gateway_id ): bool {
	if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->payment_gateways() ) {
		return false;
	}
	$gateways = WC()->payment_gateways()->payment_gateways();
	return isset( $gateways[ $gateway_id ] );
}

/**
 * Check if a gateway is enabled.
 *
 * @param string $gateway_id Gateway ID.
 * @return bool
 */
function woopayments_merge_token_continuity_gateway_enabled( string $gateway_id ): bool {
	if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->payment_gateways() ) {
		return false;
	}
	$gateways = WC()->payment_gateways()->payment_gateways();
	$gateway  = $gateways[ $gateway_id ] ?? null;
	return is_object( $gateway ) && method_exists( $gateway, 'is_available' )
		? (bool) $gateway->is_available()
		: false;
}
