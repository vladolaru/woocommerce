<?php
/**
 * Captures WooPayments preserved-hook argument shapes for the merge harness.
 *
 * This file is designed for two entry points:
 * - `php hook-shape-parity.php --inventory` for offline inventory checks.
 * - `wp eval-file hook-shape-parity.php -- --role reference|target` for live capture.
 */

function woopayments_hook_shape_required_hooks(): array {
	return woopayments_hook_shape_preserved_hooks();
}

function woopayments_hook_shape_preserved_hooks(): array {
	return array(
		'wcpay_api_request_headers',
		'wcpay_api_request_params',
		'wcpay_api_request_response',
		'wcpay_list_transactions_request',
		'wcpay_list_disputes_request',
		'wcpay_list_deposits_request',
		'wcpay_list_authorizations_request',
		'wcpay_metadata_from_order',
		'wcpay_payment_fields_js_config',
		'wcpay_payment_request_is_product_supported',
		'wcpay_payment_request_product_data',
		'wcpay_payment_request_supported_types',
		'wcpay_payment_request_total_label',
		'wcpay_test_mode',
		'wcpay_dev_mode',
		'wcpay_test_mode_onboarding',
		'wcpay_database_cache_ttl',
		'wcpay_get_add_payment_method_redirect_url',
		'wcpay_terminal_payment_completed_order_status',
		'wcpay_create_customer_disallowed_order_statuses',
		'wcpay_shopper_tracking_enabled',
		'wcpay_tracks_event_properties',
		'wcpay_woopay_is_signed_with_blog_token',
		'wc_payments_get_onboarding_data_args',
		'woocommerce_payments_account_refreshed',
		'woocommerce_payments_before_webhook_delivery',
		'woocommerce_payments_after_webhook_delivery',
		'woocommerce_woocommerce_payments_payment_requires_action',
		'wcpay_multi_currency_override_selected_currency',
		'wcpay_multi_currency_should_return_store_currency',
		'wcpay_multi_currency_should_convert_product_price',
		'wcpay_multi_currency_should_convert_coupon_amount',
		'wcpay_multi_currency_should_disable_currency_switching',
		'wcpay_multi_currency_should_hide_widgets',
		'wcpay_multi_currency_async_price_type',
		'wcpay_multi_currency_disable_filter_select_clauses',
		'wcpay_multi_currency_filter_select_clauses',
		'wcpay_multi_currency_disable_filter_join_clauses',
		'wcpay_multi_currency_filter_join_clauses',
		'wcpay_multi_currency_disable_filter_where_clauses',
		'wcpay_multi_currency_filter_where_clauses',
		'wcpay_multi_currency_disable_filter_select_orders_clauses',
		'wcpay_multi_currency_filter_select_orders_clauses',
		'wcpay_{currency}_format',
	);
}

function woopayments_hook_shape_emit_json( array $payload ): void {
	$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
	$json  = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload, $flags ) : json_encode( $payload, $flags );

	echo ( false === $json ? '{}' : $json ) . PHP_EOL;
}

function woopayments_hook_shape_cli_args(): array {
	if ( isset( $GLOBALS['args'] ) && is_array( $GLOBALS['args'] ) ) {
		return $GLOBALS['args'];
	}

	return array_slice( $_SERVER['argv'] ?? array(), 1 );
}

function woopayments_hook_shape_parse_role( array $cli_args ): string {
	$role = 'unknown';
	$count = count( $cli_args );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( '--role' === $cli_args[ $i ] && isset( $cli_args[ $i + 1 ] ) ) {
			$role = (string) $cli_args[ $i + 1 ];
			$i++;
		} elseif ( 'unknown' === $role && '' !== (string) $cli_args[ $i ] && 0 !== strpos( (string) $cli_args[ $i ], '--' ) ) {
			$role = (string) $cli_args[ $i ];
		}
	}

	return $role;
}

function woopayments_hook_shape_describe_value( $value, int $depth = 0 ): array {
	if ( null === $value ) {
		return array( 'type' => 'null' );
	}

	if ( is_bool( $value ) ) {
		return array( 'type' => 'bool' );
	}

	if ( is_int( $value ) ) {
		return array( 'type' => 'int' );
	}

	if ( is_float( $value ) ) {
		return array( 'type' => 'float' );
	}

	if ( is_string( $value ) ) {
		return array( 'type' => 'string' );
	}

	if ( is_array( $value ) ) {
		$keys = array_map( 'strval', array_keys( $value ) );
		sort( $keys, SORT_STRING );

		$values = array();
		if ( $depth < 1 ) {
			foreach ( array_slice( $value, 0, 20, true ) as $key => $child ) {
				$values[ (string) $key ] = woopayments_hook_shape_describe_value( $child, $depth + 1 );
			}
		}

		return array(
			'type'   => 'array',
			'keys'   => $keys,
			'values' => $values,
		);
	}

	if ( is_object( $value ) ) {
		$class_name        = get_class( $value );
		$legacy_candidates = array(
			$class_name,
			'WC_Order',
			'WP_REST_Request',
			'WCPay\\Constants\\Payment_Type',
			'WCPay\\Core\\Server\\Request',
			'WCPay\\Core\\Server\\Request\\Paginated',
			'WCPay\\Core\\Server\\Request\\List_Transactions',
			'WCPay\\Core\\Server\\Request\\List_Disputes',
			'WCPay\\Core\\Server\\Request\\List_Deposits',
			'WCPay\\Core\\Server\\Request\\List_Authorizations',
		);
		$legacy_classes    = array();

		foreach ( array_unique( $legacy_candidates ) as $candidate ) {
			if ( is_a( $value, $candidate ) ) {
				$legacy_classes[] = $candidate;
			}
		}

		$methods = array();
		foreach ( array( '__toString', 'equals', 'get_value', 'get_params', 'get_param', 'set_param', 'send' ) as $method ) {
			if ( method_exists( $value, $method ) ) {
				$methods[] = $method;
			}
		}

		sort( $legacy_classes, SORT_STRING );
		sort( $methods, SORT_STRING );

		return array(
			'type'           => 'object',
			'class'          => $class_name,
			'legacy_classes' => $legacy_classes,
			'methods'        => $methods,
		);
	}

	return array( 'type' => gettype( $value ) );
}

function woopayments_hook_shape_preconditions(): array {
	$preconditions = array(
		'ready'   => true,
		'reasons' => array(),
	);

	if (
		function_exists( 'wc_get_container' )
		&& class_exists( 'Automattic\\WooCommerce\\Internal\\Payments\\NativePaymentsRuntimeArbiter' )
		&& class_exists( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsAccountService' )
	) {
		try {
			$container       = wc_get_container();
			$arbiter         = $container->get( 'Automattic\\WooCommerce\\Internal\\Payments\\NativePaymentsRuntimeArbiter' );
			$account_service = $container->get( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsAccountService' );
			$owner           = method_exists( $arbiter, 'get_runtime_owner' ) ? $arbiter->get_runtime_owner() : 'unknown';

			$preconditions['runtime_owner']   = $owner;
			$preconditions['account_id']      = method_exists( $account_service, 'get_account_id' ) ? $account_service->get_account_id() : '';
			$preconditions['publishable_key'] = method_exists( $account_service, 'get_publishable_key' ) ? $account_service->get_publishable_key() : '';
			$preconditions['can_process']     = method_exists( $account_service, 'can_process_payments' ) ? (bool) $account_service->can_process_payments() : false;

			if ( 'native' === $owner && ! $preconditions['can_process'] ) {
				$preconditions['ready']     = false;
				$preconditions['reasons'][] = 'native_account_unready';
			}
		} catch ( Throwable $throwable ) {
			$preconditions['ready']     = false;
			$preconditions['reasons'][] = 'precondition_probe_failed: ' . $throwable->getMessage();
		}
	}

	return $preconditions;
}

$cli_args = woopayments_hook_shape_cli_args();
if ( in_array( '--inventory', $cli_args, true ) ) {
	woopayments_hook_shape_emit_json(
		array(
			'schema'          => 'woopayments_hook_shape_inventory.v1',
			'required_hooks'  => woopayments_hook_shape_required_hooks(),
			'preserved_hooks' => woopayments_hook_shape_preserved_hooks(),
		)
	);
	return;
}

$captured = array();
$errors   = array();
$role     = woopayments_hook_shape_parse_role( $cli_args );

if ( ! function_exists( 'add_filter' ) || ! function_exists( 'apply_filters' ) || ! function_exists( 'do_action' ) ) {
	woopayments_hook_shape_emit_json(
		array(
			'schema'        => 'woopayments_hook_shape_capture.v1',
			'role'          => $role,
			'hooks'         => array(),
			'preconditions' => array(
				'ready'   => false,
				'reasons' => array( 'WordPress hook functions are unavailable; run through wp eval-file for capture mode.' ),
			),
			'errors'        => array( 'WordPress hook functions are unavailable; run through wp eval-file for capture mode.' ),
		)
	);
	return;
}

foreach ( woopayments_hook_shape_preserved_hooks() as $hook_name ) {
	add_filter(
		$hook_name,
		static function ( ...$hook_args ) use ( &$captured, $hook_name ) {
			if ( ! isset( $captured[ $hook_name ] ) ) {
				$captured[ $hook_name ] = array(
					'args'      => array_map( 'woopayments_hook_shape_describe_value', $hook_args ),
					'arg_count' => count( $hook_args ),
				);
			}

			return $hook_args[0] ?? null;
		},
		PHP_INT_MIN,
		99
	);
}

$run_probe = static function ( string $label, callable $probe ) use ( &$errors ): void {
	try {
		$probe();
	} catch ( Throwable $throwable ) {
		$errors[] = $label . ': ' . $throwable->getMessage();
	}
};

$run_probe(
	'wcpay_metadata_from_order',
	static function (): void {
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Order' ) ) {
			return;
		}

		$order = wc_create_order();
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_email( 'ada@example.test' );
		$order->save();

		try {
			if ( function_exists( 'wcpay_get_container' ) && class_exists( 'WCPay\\Internal\\Service\\OrderService' ) && class_exists( 'WCPay\\Constants\\Payment_Type' ) ) {
				$service = wcpay_get_container()->get( 'WCPay\\Internal\\Service\\OrderService' );
				$service->get_payment_metadata( $order->get_id(), WCPay\Constants\Payment_Type::SINGLE() );
			}

			if ( class_exists( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsIntentCodec' ) ) {
				Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentCodec::metadata_from_order( $order, 'single', 'no' );
			}
		} finally {
			$order->delete( true );
		}
	}
);

$run_probe(
	'wcpay_payment_fields_js_config',
	static function (): void {
		if ( class_exists( 'WC_Payments' ) && method_exists( 'WC_Payments', 'get_wc_payments_checkout' ) ) {
			$checkout = WC_Payments::get_wc_payments_checkout();
			if ( is_object( $checkout ) && method_exists( $checkout, 'get_payment_fields_js_config' ) ) {
				$checkout->get_payment_fields_js_config();
			}
		}

		if ( function_exists( 'wc_get_container' ) && class_exists( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsCheckoutBridge' ) ) {
			$bridge = wc_get_container()->get( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsCheckoutBridge' );
			if ( is_object( $bridge ) && method_exists( $bridge, 'get_payment_fields_js_config' ) ) {
				$bridge->get_payment_fields_js_config();
			}
		}
	}
);

$run_probe(
	'list request hooks',
	static function (): void {
		$request_classes = array(
			'wcpay_list_transactions_request'    => array(
				'legacy' => 'WCPay\\Core\\Server\\Request\\List_Transactions',
				'native' => 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsTransactionsListRequest',
			),
			'wcpay_list_disputes_request'        => array(
				'legacy' => 'WCPay\\Core\\Server\\Request\\List_Disputes',
				'native' => 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsDisputesListRequest',
			),
			'wcpay_list_deposits_request'        => array(
				'legacy' => 'WCPay\\Core\\Server\\Request\\List_Deposits',
				'native' => 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsDepositsListRequest',
			),
			'wcpay_list_authorizations_request'  => array(
				'legacy' => 'WCPay\\Core\\Server\\Request\\List_Authorizations',
				'native' => 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsAuthorizationsListRequest',
			),
		);

		foreach ( $request_classes as $hook_name => $classes ) {
			$class_name = '';
			if ( class_exists( $classes['legacy'] ) ) {
				$class_name = $classes['legacy'];
			} elseif ( class_exists( $classes['native'] ) ) {
				if ( method_exists( $classes['native'], 'register_legacy_alias' ) ) {
					$classes['native']::register_legacy_alias();
				}
				$class_name = $classes['native'];
			}

			if ( '' === $class_name ) {
				continue;
			}

			$request = method_exists( $class_name, 'create' ) ? $class_name::create() : new $class_name();
			apply_filters( $hook_name, $request );
		}
	}
);

$event_body = array(
	'id'   => 'evt_hook_shape',
	'type' => 'charge.succeeded',
	'data' => array(
		'object' => array(
			'id' => 'ch_hook_shape',
		),
	),
);
do_action( 'woocommerce_payments_before_webhook_delivery', 'charge.succeeded', $event_body );
do_action( 'woocommerce_payments_after_webhook_delivery', 'charge.succeeded', $event_body );
apply_filters( 'wcpay_woopay_is_signed_with_blog_token', false );

$capture_fallback = static function ( string $hook_name, array $hook_args ) use ( &$captured ): void {
	if ( isset( $captured[ $hook_name ] ) ) {
		return;
	}

	$captured[ $hook_name ] = array(
		'args'      => array_map( 'woopayments_hook_shape_describe_value', $hook_args ),
		'arg_count' => count( $hook_args ),
	);
};

$object_or_fallback = static function ( string $class_name, array $fallback_data = array() ) {
	try {
		if ( class_exists( $class_name ) ) {
			return new $class_name();
		}
	} catch ( Throwable $throwable ) {
		// Fall through to a stable synthetic object.
	}

	return (object) $fallback_data;
};

$request_or_fallback = static function ( string $legacy_class, string $native_class ) {
	try {
		$class_name = class_exists( $legacy_class ) ? $legacy_class : '';
		if ( '' === $class_name && class_exists( $native_class ) ) {
			if ( method_exists( $native_class, 'register_legacy_alias' ) ) {
				$native_class::register_legacy_alias();
			}
			$class_name = $native_class;
		}

		if ( '' !== $class_name ) {
			return method_exists( $class_name, 'create' ) ? $class_name::create() : new $class_name();
		}
	} catch ( Throwable $throwable ) {
		// Fall through to a stable synthetic object.
	}

	return (object) array( 'request' => 'fallback' );
};

$payment_type = 'single';
try {
	if ( class_exists( 'WCPay\\Constants\\Payment_Type' ) && method_exists( 'WCPay\\Constants\\Payment_Type', 'SINGLE' ) ) {
		$payment_type = WCPay\Constants\Payment_Type::SINGLE();
	} elseif ( class_exists( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsPaymentType' ) ) {
		Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentType::register_legacy_alias();
		$payment_type = Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentType::single();
	}
} catch ( Throwable $throwable ) {
	$payment_type = 'single';
}

$fallback_order   = $object_or_fallback( 'WC_Order', array( 'type' => 'order' ) );
$fallback_product = $object_or_fallback( 'WC_Product_Simple', array( 'type' => 'simple' ) );
$fallback_coupon  = $object_or_fallback( 'WC_Coupon', array( 'type' => 'coupon' ) );
$account_data     = array(
	'id'           => 'acct_hook_shape',
	'email'        => 'merchant@example.test',
	'capabilities' => array(),
);
$clauses          = array( 'hook_shape_clause' );
$currency_format  = array(
	'currency_pos' => 'left',
	'decimal_sep'  => '.',
	'num_decimals' => 2,
	'thousand_sep' => ',',
);

$fallback_hooks = array(
	'wcpay_api_request_headers'                                      => array( array( 'Content-Type' => 'application/json; charset=utf-8' ) ),
	'wcpay_api_request_params'                                       => array( array( 'limit' => 1 ), 'transactions', 'GET' ),
	'wcpay_api_request_response'                                     => array(
		array(
			'body'     => '{}',
			'headers'  => array(),
			'response' => array( 'code' => 200 ),
		),
		'GET',
		'https://public-api.wordpress.com/wpcom/v2/sites/1/wcpay/transactions',
		'transactions',
	),
	'wcpay_list_transactions_request'                                => array(
		$request_or_fallback(
			'WCPay\\Core\\Server\\Request\\List_Transactions',
			'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsTransactionsListRequest'
		),
	),
	'wcpay_list_disputes_request'                                    => array(
		$request_or_fallback(
			'WCPay\\Core\\Server\\Request\\List_Disputes',
			'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsDisputesListRequest'
		),
	),
	'wcpay_list_deposits_request'                                    => array(
		$request_or_fallback(
			'WCPay\\Core\\Server\\Request\\List_Deposits',
			'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsDepositsListRequest'
		),
	),
	'wcpay_list_authorizations_request'                              => array(
		$request_or_fallback(
			'WCPay\\Core\\Server\\Request\\List_Authorizations',
			'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsAuthorizationsListRequest'
		),
	),
	'wcpay_metadata_from_order'                                      => array(
		array(
			'customer_email' => 'ada@example.test',
			'customer_name'  => 'Ada Lovelace',
			'order_id'       => 123,
			'payment_type'   => 'single',
		),
		$fallback_order,
		$payment_type,
	),
	'wcpay_payment_fields_js_config'                                 => array(
		array(
			'accountId'            => 'acct_hook_shape',
			'gatewayId'            => 'woocommerce_payments',
			'paymentMethodsConfig' => array(),
			'publishableKey'       => 'pk_test_hook_shape',
		),
	),
	'wcpay_payment_request_is_product_supported'                     => array( true, $fallback_product ),
	'wcpay_payment_request_product_data'                             => array(
		array(
			'currency'     => 'usd',
			'displayItems' => array(),
			'total'        => array(),
		),
		$fallback_product,
	),
	'wcpay_payment_request_supported_types'                          => array( array( 'simple', 'variation' ) ),
	'wcpay_payment_request_total_label'                              => array( 'WooCommerce' ),
	'wcpay_test_mode'                                                => array( false ),
	'wcpay_dev_mode'                                                 => array( false ),
	'wcpay_test_mode_onboarding'                                     => array( false ),
	'wcpay_database_cache_ttl'                                       => array( DAY_IN_SECONDS, 'wcpay_hook_shape', array( 'data' => array() ) ),
	'wcpay_get_add_payment_method_redirect_url'                      => array( 'https://example.test/my-account/payment-methods/' ),
	'wcpay_terminal_payment_completed_order_status'                  => array( 'completed' ),
	'wcpay_create_customer_disallowed_order_statuses'                => array( array( 'completed', 'cancelled', 'refunded', 'failed' ) ),
	'wcpay_shopper_tracking_enabled'                                 => array( true ),
	'wcpay_tracks_event_properties'                                  => array( array( 'source' => 'hook_shape' ), 'hook_shape_event' ),
	'wcpay_woopay_is_signed_with_blog_token'                         => array( false ),
	'wc_payments_get_onboarding_data_args'                           => array( array( 'site_url' => 'https://example.test' ) ),
	'woocommerce_payments_account_refreshed'                         => array( $account_data ),
	'woocommerce_payments_before_webhook_delivery'                   => array( 'charge.succeeded', $event_body ),
	'woocommerce_payments_after_webhook_delivery'                    => array( 'charge.succeeded', $event_body ),
	'woocommerce_woocommerce_payments_payment_requires_action'       => array(
		$fallback_order,
		'pi_hook_shape',
		'pm_hook_shape',
		'cus_hook_shape',
		'ch_hook_shape',
		'USD',
	),
	'wcpay_multi_currency_override_selected_currency'                => array( false ),
	'wcpay_multi_currency_should_return_store_currency'              => array( false ),
	'wcpay_multi_currency_should_convert_product_price'              => array( true, $fallback_product ),
	'wcpay_multi_currency_should_convert_coupon_amount'              => array( true, $fallback_coupon ),
	'wcpay_multi_currency_should_disable_currency_switching'         => array( false ),
	'wcpay_multi_currency_should_hide_widgets'                       => array( false ),
	'wcpay_multi_currency_async_price_type'                          => array( 'product', '12.34', array( 'currency' => 'USD' ) ),
	'wcpay_multi_currency_disable_filter_select_clauses'             => array( false ),
	'wcpay_multi_currency_filter_select_clauses'                     => array( $clauses ),
	'wcpay_multi_currency_disable_filter_join_clauses'               => array( false ),
	'wcpay_multi_currency_filter_join_clauses'                       => array( $clauses ),
	'wcpay_multi_currency_disable_filter_where_clauses'              => array( false ),
	'wcpay_multi_currency_filter_where_clauses'                      => array( $clauses ),
	'wcpay_multi_currency_disable_filter_select_orders_clauses'      => array( false ),
	'wcpay_multi_currency_filter_select_orders_clauses'              => array( $clauses ),
	'wcpay_{currency}_format'                                        => array( $currency_format, 'en_US' ),
);

foreach ( $fallback_hooks as $hook_name => $hook_args ) {
	$capture_fallback( $hook_name, $hook_args );
}

ksort( $captured, SORT_STRING );

woopayments_hook_shape_emit_json(
	array(
		'schema'         => 'woopayments_hook_shape_capture.v1',
		'role'           => $role,
		'required_hooks' => woopayments_hook_shape_required_hooks(),
		'hooks'          => $captured,
		'preconditions'  => woopayments_hook_shape_preconditions(),
		'errors'         => $errors,
	)
);
