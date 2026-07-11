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

function woopayments_hook_shape_probe_manifest(): array {
	$probe = static function ( string $group, string $plugin_callable, string $native_callable ): array {
		return array(
			'group'           => $group,
			'plugin_callable' => $plugin_callable,
			'native_callable' => $native_callable,
		);
	};

	return array(
		'wcpay_api_request_headers' => $probe( 'api_transport', 'WC_Payments_API_Client::get_disputes', 'WooPaymentsApiClient::get_disputes' ),
		'wcpay_api_request_params' => $probe( 'api_transport', 'WC_Payments_API_Client::get_disputes', 'WooPaymentsApiClient::get_disputes' ),
		'wcpay_api_request_response' => $probe( 'api_transport', 'WC_Payments_API_Client::get_disputes', 'WooPaymentsApiClient::get_disputes' ),
		'wcpay_list_fraud_outcome_transactions_request' => $probe( 'fraud_reports', 'WC_Payments_API_Client::list_fraud_outcome_transactions', 'WooPaymentsTransactionsRestController::get_fraud_outcome_transactions' ),
		'wcpay_list_fraud_outcome_transactions_summary_request' => $probe( 'fraud_reports', 'WC_Payments_API_Client::list_fraud_outcome_transactions_summary', 'WooPaymentsTransactionsRestController::get_fraud_outcome_transactions_summary' ),
		'wcpay_get_fraud_outcome_transactions_search_autocomplete_request' => $probe( 'fraud_reports', 'WC_Payments_API_Client::get_fraud_outcome_transactions_search_autocomplete', 'WooPaymentsTransactionsRestController::get_fraud_outcome_transactions_search_autocomplete' ),
		'wcpay_get_fraud_outcome_transactions_export_request' => $probe( 'fraud_reports', 'WC_Payments_API_Client::get_fraud_outcome_transactions_export', 'WooPaymentsTransactionsRestController::get_fraud_outcome_transactions_export' ),
		'wcpay_get_pm_promotions_request' => $probe( 'api_promotions', 'WC_Payments_PM_Promotions_Service::get_visible_promotions', 'WooPaymentsApiClient::get_pm_promotions' ),
		'wcpay_activate_pm_promotion_request' => $probe( 'api_promotions', 'WC_Payments_PM_Promotions_Service::activate_promotion', 'WooPaymentsApiClient::activate_pm_promotion' ),
		'wcpay_get_authorization_request' => $probe( 'api_authorizations', 'WC_REST_Payments_Authorizations_Controller::get_authorization', 'WooPaymentsApiClient::get_authorization' ),
		'wcpay_list_documents_request' => $probe( 'api_documents', 'WC_REST_Payments_Documents_Controller::get_documents', 'WooPaymentsApiClient::get_documents' ),
		'wcpay_validate_vat_request' => $probe( 'api_vat', 'WC_REST_Payments_VAT_Controller::validate_vat', 'WooPaymentsApiClient::validate_vat' ),
		'wcpay_get_active_loan_summary_request' => $probe( 'api_capital', 'WC_REST_Payments_Capital_Controller::get_active_loan_summary', 'WooPaymentsApiClient::get_capital_active_loan_summary' ),
		'wcpay_get_loans_request' => $probe( 'api_capital', 'WC_REST_Payments_Capital_Controller::get_loans', 'WooPaymentsApiClient::get_capital_loans' ),
		'wcpay_get_account_capital_link' => $probe( 'api_capital', 'WC_Payments_Redirect_Service::get_redirect_url', 'WooPaymentsApiClient::create_capital_link' ),
		'wc_pay_get_authorizations_summary' => $probe( 'api_authorizations', 'WC_REST_Payments_Authorizations_Controller::get_authorizations_summary', 'WooPaymentsApiClient::get_authorizations_summary' ),
		'wcpay_get_dispute_status_counts' => $probe( 'api_disputes', 'WC_Payments_Admin::get_disputes_awaiting_response_count', 'WooPaymentsApiClient::get_dispute_status_counts' ),
		'wcpay_list_transactions_request' => $probe( 'list_transactions', 'List_Transactions::send', 'WooPaymentsTransactionsRestController::get_transactions' ),
		'wcpay_list_disputes_request' => $probe( 'list_disputes', 'List_Disputes::send', 'WooPaymentsDisputesRestController::get_disputes' ),
		'wcpay_list_deposits_request' => $probe( 'list_deposits', 'List_Deposits::send', 'WooPaymentsDepositsRestController::get_deposits' ),
		'wcpay_list_authorizations_request' => $probe( 'list_authorizations', 'List_Authorizations::send', 'WooPaymentsAuthorizationsRestController::get_authorizations' ),
		'wcpay_metadata_from_order' => $probe( 'order_metadata', 'OrderService::get_payment_metadata', 'WooPaymentsIntentCodec::metadata_from_order' ),
		'wcpay_payment_fields_js_config' => $probe( 'checkout_config', 'WC_Payments_Checkout::get_payment_fields_js_config', 'WooPaymentsCheckoutBridge::get_payment_fields_js_config' ),
		'wc_payments_thank_you_page_bnpl_payment_method_logo_url' => $probe( 'order_success_logos', 'WC_Payments_Order_Success_Page::show_lpm_payment_method_name', 'WooPaymentsOrderSuccessPage::render_definition_title' ),
		'wc_payments_thank_you_page_lpm_payment_method_logo_url' => $probe( 'order_success_logos', 'WC_Payments_Order_Success_Page::show_lpm_payment_method_name', 'WooPaymentsOrderSuccessPage::render_definition_title' ),
		'wc_payments_account_id_for_intent_confirmation' => $probe( 'checkout_config', 'WC_Payments_Checkout::get_payment_fields_js_config', 'WooPaymentsCheckoutBridge::get_payment_fields_js_config' ),
		'wcpay_is_woopay_store_api_request' => $probe( 'store_api_request', 'WC_Payment_Gateway_WCPay::process_payment', 'NativeWooPaymentsGateway::get_fraud_prevention_error_message' ),
		'wcpay_payment_request_is_product_supported' => $probe( 'express_product', 'WC_Payments_Express_Checkout_Button_Helper::is_product_supported', 'WooPaymentsExpressCheckoutService::is_product_supported' ),
		'wcpay_payment_request_product_data' => $probe( 'express_product', 'WC_Payments_Express_Checkout_Button_Helper::get_product_data', 'WooPaymentsExpressCheckoutService::get_product_data' ),
		'wcpay_payment_request_supported_types' => $probe( 'express_product', 'WC_Payments_Express_Checkout_Button_Helper::get_supported_product_types', 'WooPaymentsExpressCheckoutService::get_supported_product_types' ),
		'wcpay_payment_request_total_label' => $probe( 'express_product', 'WC_Payments_Express_Checkout_Button_Helper::get_total_label', 'WooPaymentsExpressCheckoutService::get_total_label' ),
		'wcpay_test_mode' => $probe( 'account_mode', 'WCPay\Core\Mode::is_test', 'WooPaymentsAccountService::is_test_mode_enabled' ),
		'wcpay_dev_mode' => $probe( 'account_mode', 'WCPay\Core\Mode::is_dev', 'WooPaymentsAccountService::is_dev_mode_enabled' ),
		'wcpay_test_mode_onboarding' => $probe( 'account_mode', 'WCPay\Core\Mode::is_test_mode_onboarding', 'WooPaymentsAccountService::is_test_mode_onboarding_enabled' ),
		'wcpay_database_cache_ttl' => $probe( 'database_cache', 'WCPay\Database_Cache::get_ttl', 'WooPaymentsAccountService::get_account_cache_ttl' ),
		'wcpay_get_add_payment_method_redirect_url' => $probe( 'add_payment_method', 'WC_Payment_Gateway_WCPay::add_payment_method', 'NativeWooPaymentsGateway::add_payment_method' ),
		'wcpay_terminal_payment_completed_order_status' => $probe( 'terminal_payment', 'WC_Payments_Order_Service::mark_terminal_payment_completed', 'WooPaymentsMobileRestController::mark_terminal_payment_completed' ),
		'wcpay_create_customer_disallowed_order_statuses' => $probe( 'customer_creation', 'WC_REST_Payments_Orders_Controller::create_customer', 'WooPaymentsMobileRestController::create_customer' ),
		'wcpay_shopper_tracking_enabled' => $probe( 'tracking', 'WooPay_Tracker::should_enable_tracking', 'WooPaymentsFrontendTrackingController::is_shopper_tracking_enabled' ),
		'wcpay_tracks_event_properties' => $probe( 'tracking', 'WooPay_Tracker::tracks_record_event', 'WooPaymentsFrontendTrackingController::record_user_event' ),
		'wcpay_woopay_is_signed_with_blog_token' => $probe( 'woopay_signature', 'WooPay_Session::has_valid_request_signature', 'WooPaymentsWooPaySessionController::check_permission' ),
		'wcpay_webhook_platform_checkout_order_status_changed' => $probe( 'woopay_order_status', 'WCPay\WooPay\WooPay_Order_Status_Sync::send_webhook', 'WooPaymentsWooPayOrderStatusSync::send_webhook' ),
		'wc_payments_get_onboarding_data_args' => $probe( 'onboarding', 'WC_Payments_API_Client::get_onboarding_data', 'WooPaymentsApiClient::initialize_onboarding' ),
		'woocommerce_payments_account_refreshed' => $probe( 'account_refresh', 'WC_Payments_Account::refresh_account_data', 'WooPaymentsAccountService::refresh_account_data' ),
		'woocommerce_payments_before_webhook_delivery' => $probe( 'webhook_delivery', 'Webhook_Processing_Service::process', 'WooPaymentsEventIngestor::process' ),
		'woocommerce_payments_after_webhook_delivery' => $probe( 'webhook_delivery', 'Webhook_Processing_Service::process', 'WooPaymentsEventIngestor::process' ),
		'woocommerce_woocommerce_payments_payment_requires_action' => $probe( 'payment_requires_action', 'WC_Payment_Gateway_WCPay::process_payment_for_order', 'NativeWooPaymentsGateway::maybe_handle_subscription_customer_action_required' ),
		'wcpay_multi_currency_storefront_widget_css' => $probe( 'mc_storefront', 'StorefrontIntegration::add_inline_css', 'MultiCurrencyStorefrontIntegrationController::handle_wp_enqueue_scripts' ),
		'wcpay_multi_currency_storefront_widget_instance' => $probe( 'mc_storefront', 'StorefrontIntegration::modify_breadcrumb_defaults', 'MultiCurrencyStorefrontIntegrationController::handle_woocommerce_breadcrumb_defaults' ),
		'wcpay_multi_currency_storefront_widget_args' => $probe( 'mc_storefront', 'StorefrontIntegration::modify_breadcrumb_defaults', 'MultiCurrencyStorefrontIntegrationController::handle_woocommerce_breadcrumb_defaults' ),
		'wcpay_multi_currency_override_notice_country' => $probe( 'mc_notice', 'MultiCurrency::display_geolocation_currency_update_notice', 'MultiCurrencySelectedCurrencyController::handle_wp_footer' ),
		'wcpay_multi_currency_override_notice_currency_name' => $probe( 'mc_notice', 'MultiCurrency::display_geolocation_currency_update_notice', 'MultiCurrencySelectedCurrencyController::handle_wp_footer' ),
		'wcpay_multi_currency_apply_charm_only_to_products' => $probe( 'mc_frontend', 'MultiCurrency::get_apply_charm_only_to_products', 'MultiCurrencyFrontendProjectionService::get_public_config' ),
		'wcpay_multi_currency_override_selected_currency' => $probe( 'mc_state', 'Compatibility::override_selected_currency', 'MultiCurrencyCompatibilityController::override_selected_currency' ),
		'wcpay_multi_currency_should_return_store_currency' => $probe( 'mc_compatibility', 'Compatibility::should_return_store_currency', 'MultiCurrencyCompatibilityController::should_return_store_currency' ),
		'wcpay_multi_currency_should_convert_product_price' => $probe( 'mc_compatibility', 'Compatibility::should_convert_product_price', 'MultiCurrencyCompatibilityController::should_convert_product_price' ),
		'wcpay_multi_currency_should_convert_coupon_amount' => $probe( 'mc_compatibility', 'Compatibility::should_convert_coupon_amount', 'MultiCurrencyCompatibilityController::should_convert_coupon_amount' ),
		'wcpay_multi_currency_should_disable_currency_switching' => $probe( 'mc_compatibility', 'Compatibility::should_disable_currency_switching', 'MultiCurrencyCompatibilityController::should_disable_currency_switching' ),
		'wcpay_multi_currency_should_hide_widgets' => $probe( 'mc_compatibility', 'Compatibility::should_hide_widgets', 'MultiCurrencyCompatibilityController::should_hide_widgets' ),
		'wcpay_multi_currency_async_price_type' => $probe( 'mc_async', 'AsyncPriceRenderer::wrap_price_with_skeleton', 'MultiCurrencyAsyncPriceRendererController::handle_wc_price' ),
		'wcpay_multi_currency_disable_filter_select_clauses' => $probe( 'mc_analytics', 'Analytics::filter_select_clauses', 'MultiCurrencyAnalyticsController::handle_woocommerce_analytics_clauses_select' ),
		'wcpay_multi_currency_filter_select_clauses' => $probe( 'mc_analytics', 'Analytics::filter_select_clauses', 'MultiCurrencyAnalyticsController::handle_woocommerce_analytics_clauses_select' ),
		'wcpay_multi_currency_disable_filter_join_clauses' => $probe( 'mc_analytics', 'Analytics::filter_join_clauses', 'MultiCurrencyAnalyticsController::handle_woocommerce_analytics_clauses_join' ),
		'wcpay_multi_currency_filter_join_clauses' => $probe( 'mc_analytics', 'Analytics::filter_join_clauses', 'MultiCurrencyAnalyticsController::handle_woocommerce_analytics_clauses_join' ),
		'wcpay_multi_currency_disable_filter_where_clauses' => $probe( 'mc_analytics', 'Analytics::filter_where_clauses', 'MultiCurrencyAnalyticsController::handle_woocommerce_analytics_clauses_where' ),
		'wcpay_multi_currency_filter_where_clauses' => $probe( 'mc_analytics', 'Analytics::filter_where_clauses', 'MultiCurrencyAnalyticsController::handle_woocommerce_analytics_clauses_where' ),
		'wcpay_multi_currency_disable_filter_select_orders_clauses' => $probe( 'mc_analytics', 'Analytics::filter_select_orders_clauses', 'MultiCurrencyAnalyticsController::handle_woocommerce_analytics_clauses_select_orders' ),
		'wcpay_multi_currency_filter_select_orders_clauses' => $probe( 'mc_analytics', 'Analytics::filter_select_orders_clauses', 'MultiCurrencyAnalyticsController::handle_woocommerce_analytics_clauses_select_orders' ),
		'wcpay_{currency}_format' => $probe( 'mc_localization', 'WC_Payments_Localization_Service::get_currency_format', 'MultiCurrencyLocalizationService::get_currency_format' ),
	);
}

function woopayments_hook_shape_preserved_hooks(): array {
	return array_keys( woopayments_hook_shape_probe_manifest() );
}

function woopayments_hook_shape_runtime_hook_name( string $hook_name ): string {
	return str_replace( '{currency}', 'usd', $hook_name );
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
	$role        = 'unknown';
	$valid_roles = array( 'reference', 'target', 'offline' );
	$count       = count( $cli_args );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( '--role' === $cli_args[ $i ] && isset( $cli_args[ $i + 1 ] ) ) {
			$candidate = (string) $cli_args[ $i + 1 ];
			$role      = in_array( $candidate, $valid_roles, true ) ? $candidate : 'unknown';
			$i++;
		} elseif ( in_array( (string) $cli_args[ $i ], $valid_roles, true ) ) {
			$role = (string) $cli_args[ $i ];
		}
	}

	return $role;
}

function woopayments_hook_shape_runtime_owner(): string {
	if ( function_exists( 'wc_get_container' ) && class_exists( 'Automattic\\WooCommerce\\Internal\\Payments\\NativePaymentsRuntimeArbiter' ) ) {
		try {
			$arbiter = wc_get_container()->get( 'Automattic\\WooCommerce\\Internal\\Payments\\NativePaymentsRuntimeArbiter' );
			if ( is_object( $arbiter ) && method_exists( $arbiter, 'get_runtime_owner' ) ) {
				return (string) $arbiter->get_runtime_owner();
			}
		} catch ( Throwable $throwable ) {
			unset( $throwable );
		}
	}

	if ( class_exists( 'WC_Payments' ) ) {
		return 'plugin';
	}

	if ( class_exists( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\Api\\WooPaymentsApiClient' ) ) {
		return 'native';
	}

	return 'none';
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
		if ( $depth < 4 ) {
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
			'probe_manifest'  => woopayments_hook_shape_probe_manifest(),
		)
	);
	return;
}

$captured       = array();
$errors         = array();
$role           = woopayments_hook_shape_parse_role( $cli_args );
$capture_context = null;

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
	$runtime_hook_name = woopayments_hook_shape_runtime_hook_name( $hook_name );
	add_filter(
		$runtime_hook_name,
		static function ( ...$hook_args ) use ( &$captured, &$capture_context, $hook_name, $runtime_hook_name ) {
			$expected_hooks = is_array( $capture_context ) && is_array( $capture_context['expected_hooks'] ?? null )
				? $capture_context['expected_hooks']
				: array();
			if ( ! in_array( $hook_name, $expected_hooks, true ) ) {
				return $hook_args[0] ?? null;
			}

			if ( ! isset( $captured[ $hook_name ] ) ) {
				$capture_kind = (string) ( $capture_context['kind'] ?? 'unbound' );
				$captured[ $hook_name ] = array(
					'args'         => array_map( 'woopayments_hook_shape_describe_value', $hook_args ),
					'arg_count'    => count( $hook_args ),
					'observed'     => 'surrounding_path' === $capture_kind,
					'capture'      => $capture_kind,
					'probe_group'  => (string) ( $capture_context['group'] ?? '' ),
					'runtime_hook' => $runtime_hook_name,
				);
			}

			return $hook_args[0] ?? null;
		},
		PHP_INT_MIN,
		99
	);
}

$run_product_probe_group = static function ( string $label, array $expected_hooks, callable $probe ) use ( &$capture_context, &$errors ): void {
	$manifest         = woopayments_hook_shape_probe_manifest();
	$groups           = array_unique(
		array_map(
			static fn( string $hook_name ): string => (string) ( $manifest[ $hook_name ]['group'] ?? '' ),
			$expected_hooks
		)
	);
	$previous_context = $capture_context;
	$capture_context  = array(
		'kind'           => 'surrounding_path',
		'group'          => 1 === count( $groups ) ? (string) reset( $groups ) : $label,
		'expected_hooks' => array_values( $expected_hooks ),
	);

	try {
		$probe();
	} catch ( Throwable $throwable ) {
		$errors[] = $label . ': ' . $throwable->getMessage();
	} finally {
		$capture_context = $previous_context;
	}
};

$run_diagnostic_probe = static function ( string $hook_name, callable $probe ) use ( &$capture_context, &$errors ): void {
	$manifest         = woopayments_hook_shape_probe_manifest();
	$previous_context = $capture_context;
	$capture_context  = array(
		'kind'           => 'direct_boundary_probe',
		'group'          => (string) ( $manifest[ $hook_name ]['group'] ?? '' ),
		'expected_hooks' => array( $hook_name ),
	);

	try {
		$probe();
	} catch ( Throwable $throwable ) {
		$errors[] = $hook_name . ' hook boundary: ' . $throwable->getMessage();
	} finally {
		$capture_context = $previous_context;
	}
};

$run_until_hook = static function ( string $hook_name, callable $probe ) use ( &$capture_context, &$captured, &$errors ): bool {
	if ( isset( $captured[ $hook_name ] ) ) {
		return true;
	}

	$runtime_hook_name     = woopayments_hook_shape_runtime_hook_name( $hook_name );
	$probe_complete_code   = 5727056;
	$probe_complete_marker = 'WooPayments hook-shape probe completed at ' . $hook_name;
	$stop_after_hook       = static function () use ( $probe_complete_code, $probe_complete_marker ): void {
		throw new Error( $probe_complete_marker, $probe_complete_code );
	};

	add_filter( $runtime_hook_name, $stop_after_hook, PHP_INT_MIN + 1, 99 );
	$manifest         = woopayments_hook_shape_probe_manifest();
	$previous_context = $capture_context;
	$capture_context  = array(
		'kind'           => 'surrounding_path',
		'group'          => (string) ( $manifest[ $hook_name ]['group'] ?? '' ),
		'expected_hooks' => array( $hook_name ),
	);

	try {
		$probe();
	} catch ( Error $complete ) {
		if ( $probe_complete_code !== $complete->getCode() || $probe_complete_marker !== $complete->getMessage() ) {
			$errors[] = $hook_name . ' surrounding path: ' . $complete->getMessage();
		}
	} catch ( Throwable $throwable ) {
		$errors[] = $hook_name . ' surrounding path: ' . $throwable->getMessage();
	} finally {
		$capture_context = $previous_context;
		if ( function_exists( 'remove_filter' ) ) {
			remove_filter( $runtime_hook_name, $stop_after_hook, PHP_INT_MIN + 1 );
		}
	}

	return isset( $captured[ $hook_name ] );
};

$runtime_owner = woopayments_hook_shape_runtime_owner();

$invoke_object_method = static function ( object $object, string $method, array $arguments = array() ) {
	if ( ! method_exists( $object, $method ) ) {
		return null;
	}

	$reflection = new ReflectionMethod( $object, $method );
	$reflection->setAccessible( true );

	return $reflection->invokeArgs( $object, $arguments );
};

$read_object_property = static function ( object $object, string $property ) {
	$reflection = new ReflectionObject( $object );
	while ( ! $reflection->hasProperty( $property ) && false !== $reflection->getParentClass() ) {
		$reflection = $reflection->getParentClass();
	}
	if ( ! $reflection->hasProperty( $property ) ) {
		return null;
	}

	$reflected_property = $reflection->getProperty( $property );
	$reflected_property->setAccessible( true );

	return $reflected_property->getValue( $object );
};

$write_object_property = static function ( object $object, string $property, $value ): bool {
	$reflection = new ReflectionObject( $object );
	while ( ! $reflection->hasProperty( $property ) && false !== $reflection->getParentClass() ) {
		$reflection = $reflection->getParentClass();
	}
	if ( ! $reflection->hasProperty( $property ) ) {
		return false;
	}

	$reflected_property = $reflection->getProperty( $property );
	$reflected_property->setAccessible( true );
	$reflected_property->setValue( $object, $value );

	return true;
};

$run_product_probe_group(
	'WooPayments API client surrounding path',
	array( 'wcpay_api_request_headers', 'wcpay_api_request_params', 'wcpay_api_request_response' ),
	static function () use ( $runtime_owner ): void {
		$intercept_http = static function () {
			return array(
				'body'          => '{"data":[]}',
				'response'      => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'headers'       => array( 'content-type' => 'application/json' ),
				'cookies'       => array(),
				'http_response' => null,
			);
		};

		add_filter( 'pre_http_request', $intercept_http, PHP_INT_MIN, 3 );

		try {
			if ( 'plugin' === $runtime_owner && class_exists( 'WC_Payments' ) && method_exists( 'WC_Payments', 'get_payments_api_client' ) ) {
				$api_client = WC_Payments::get_payments_api_client();
				if ( is_object( $api_client ) && method_exists( $api_client, 'get_disputes' ) ) {
					$api_client->get_disputes( array() );
				}
				return;
			}

			if (
				'native' === $runtime_owner
				&& function_exists( 'wc_get_container' )
				&& class_exists( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\Api\\WooPaymentsApiClient' )
			) {
				$api_client = wc_get_container()->get( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\Api\\WooPaymentsApiClient' );
				if ( is_object( $api_client ) && method_exists( $api_client, 'get_disputes' ) ) {
					$api_client->get_disputes( array() );
				}
			}
		} finally {
			if ( function_exists( 'remove_filter' ) ) {
				remove_filter( 'pre_http_request', $intercept_http, PHP_INT_MIN );
			}
		}
	}
);

$list_request_probes = array(
	'wcpay_list_transactions_request'   => array(
		'legacy_class'      => 'WCPay\\Core\\Server\\Request\\List_Transactions',
		'native_controller' => 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsTransactionsRestController',
		'native_method'     => 'get_transactions',
		'route'             => '/wc/v3/payments/transactions',
	),
	'wcpay_list_disputes_request'       => array(
		'legacy_class'      => 'WCPay\\Core\\Server\\Request\\List_Disputes',
		'native_controller' => 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsDisputesRestController',
		'native_method'     => 'get_disputes',
		'route'             => '/wc/v3/payments/disputes',
	),
	'wcpay_list_deposits_request'       => array(
		'legacy_class'      => 'WCPay\\Core\\Server\\Request\\List_Deposits',
		'native_controller' => 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsDepositsRestController',
		'native_method'     => 'get_deposits',
		'route'             => '/wc/v3/payments/deposits',
	),
	'wcpay_list_authorizations_request' => array(
		'legacy_class'      => 'WCPay\\Core\\Server\\Request\\List_Authorizations',
		'native_controller' => 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsAuthorizationsRestController',
		'native_method'     => 'get_authorizations',
		'route'             => '/wc/v3/payments/authorizations',
	),
);

foreach ( $list_request_probes as $hook_name => $probe_definition ) {
	$run_until_hook(
		$hook_name,
		static function () use ( $probe_definition, $runtime_owner ): void {
			if ( 'plugin' === $runtime_owner && class_exists( $probe_definition['legacy_class'] ) ) {
				$request = $probe_definition['legacy_class']::create();
				if ( is_object( $request ) && method_exists( $request, 'send' ) ) {
					$request->send();
				}
				return;
			}

			if (
				'native' !== $runtime_owner
				|| ! function_exists( 'wc_get_container' )
				|| ! class_exists( 'WP_REST_Request' )
				|| ! class_exists( $probe_definition['native_controller'] )
			) {
				return;
			}

			$controller = wc_get_container()->get( $probe_definition['native_controller'] );
			$method     = $probe_definition['native_method'];
			if ( ! is_object( $controller ) || ! method_exists( $controller, $method ) ) {
				return;
			}

			$request = new WP_REST_Request( 'GET', $probe_definition['route'] );
			$request->set_param( 'page', 1 );
			$request->set_param( 'pagesize', 1 );
			$controller->$method( $request );
		}
	);
}

$api_request_hooks = array(
	'wcpay_list_fraud_outcome_transactions_request',
	'wcpay_list_fraud_outcome_transactions_summary_request',
	'wcpay_get_fraud_outcome_transactions_search_autocomplete_request',
	'wcpay_get_fraud_outcome_transactions_export_request',
	'wcpay_get_pm_promotions_request',
	'wcpay_activate_pm_promotion_request',
	'wcpay_get_authorization_request',
	'wcpay_list_documents_request',
	'wcpay_validate_vat_request',
	'wcpay_get_active_loan_summary_request',
	'wcpay_get_loans_request',
	'wcpay_get_account_capital_link',
	'wc_pay_get_authorizations_summary',
	'wcpay_get_dispute_status_counts',
);

foreach ( $api_request_hooks as $hook_name ) {
	$run_until_hook(
		$hook_name,
		static function () use ( $hook_name, $runtime_owner ): void {
			if ( 'plugin' === $runtime_owner ) {
				$fraud_methods = array(
					'wcpay_list_fraud_outcome_transactions_request' => 'list_fraud_outcome_transactions',
					'wcpay_list_fraud_outcome_transactions_summary_request' => 'list_fraud_outcome_transactions_summary',
					'wcpay_get_fraud_outcome_transactions_search_autocomplete_request' => 'get_fraud_outcome_transactions_search_autocomplete',
					'wcpay_get_fraud_outcome_transactions_export_request' => 'get_fraud_outcome_transactions_export',
				);
				if ( isset( $fraud_methods[ $hook_name ] ) && class_exists( 'WCPay\Core\Server\Request\List_Fraud_Outcome_Transactions' ) && class_exists( 'WC_Payments' ) ) {
					$request_class = 'WCPay\Core\Server\Request\List_Fraud_Outcome_Transactions';
					$request       = $request_class::create();
					$request->set_status( 'block' );
					$method        = $fraud_methods[ $hook_name ];
					WC_Payments::get_payments_api_client()->$method( $request );
					return;
				}

				$plugin_request_classes = array(
					'wcpay_get_pm_promotions_request'             => 'WCPay\Core\Server\Request\Get_PM_Promotions',
					'wcpay_activate_pm_promotion_request'          => 'WCPay\Core\Server\Request\Activate_PM_Promotion',
					'wcpay_list_documents_request'                => 'WCPay\Core\Server\Request\List_Documents',
					'wcpay_get_account_capital_link'              => 'WCPay\Core\Server\Request\Get_Account_Capital_Link',
				);
				if ( isset( $plugin_request_classes[ $hook_name ] ) && class_exists( $plugin_request_classes[ $hook_name ] ) ) {
					$request_class = $plugin_request_classes[ $hook_name ];
					$request       = 'wcpay_activate_pm_promotion_request' === $hook_name
						? $request_class::create( 'hook-shape-promotion' )
						: $request_class::create();
					if ( 'wcpay_get_account_capital_link' === $hook_name ) {
						$request->set_type( 'capital_financing_offer' );
						$request->set_return_url( 'http://localhost/hook-shape-return' );
						$request->set_refresh_url( 'http://localhost/hook-shape-refresh' );
					}
					$request->send();
					return;
				}

				if ( 'wcpay_validate_vat_request' === $hook_name && class_exists( 'WP_REST_Request' ) ) {
					if ( ! did_action( 'rest_api_init' ) ) {
						do_action( 'rest_api_init', rest_get_server() );
					}
					if ( ! class_exists( 'WC_REST_Payments_VAT_Controller' ) && defined( 'WCPAY_ABSPATH' ) ) {
						include_once WCPAY_ABSPATH . 'includes/admin/class-wc-rest-payments-vat-controller.php';
					}
					if ( ! class_exists( 'WC_REST_Payments_VAT_Controller' ) || ! method_exists( 'WC_Payments', 'get_payments_api_client' ) ) {
						return;
					}
					$controller = new WC_REST_Payments_VAT_Controller( WC_Payments::get_payments_api_client() );
					$request    = new WP_REST_Request( 'GET', '/wc/v3/payments/vat/EU123456789' );
					$request->set_param( 'vat_number', 'EU123456789' );
					$controller->validate_vat( $request );
					return;
				}

				$plugin_controller_methods = array(
					'wcpay_get_authorization_request'        => array( 'WC_REST_Payments_Authorizations_Controller', 'get_authorization' ),
					'wc_pay_get_authorizations_summary'       => array( 'WC_REST_Payments_Authorizations_Controller', 'get_authorizations_summary' ),
					'wcpay_get_active_loan_summary_request'   => array( 'WC_REST_Payments_Capital_Controller', 'get_active_loan_summary' ),
					'wcpay_get_loans_request'                 => array( 'WC_REST_Payments_Capital_Controller', 'get_loans' ),
				);
				if ( isset( $plugin_controller_methods[ $hook_name ] ) ) {
					list( $controller_class, $method ) = $plugin_controller_methods[ $hook_name ];
					if ( ! class_exists( $controller_class ) || ! method_exists( 'WC_Payments', 'get_payments_api_client' ) ) {
						return;
					}
					$controller = new $controller_class( WC_Payments::get_payments_api_client() );
					if ( in_array( $hook_name, array( 'wc_pay_get_authorizations_summary', 'wcpay_get_active_loan_summary_request', 'wcpay_get_loans_request' ), true ) ) {
						$controller->$method();
						return;
					}
					if ( ! class_exists( 'WP_REST_Request' ) ) {
						return;
					}
					$request = new WP_REST_Request( 'GET', '/wc/v3/payments/hook-shape' );
					$request->set_param( 'payment_intent_id', 'pi_hook_shape' );
					$request->set_param( 'vat_number', 'EU123456789' );
					$controller->$method( $request );
					return;
				}

				if ( 'wcpay_get_dispute_status_counts' === $hook_name && class_exists( 'WCPay\Core\Server\Request' ) && class_exists( 'WC_Payments_API_Client' ) ) {
					$request_class = 'WCPay\Core\Server\Request';
					$request       = $request_class::get( WC_Payments_API_Client::DISPUTES_API . '/status_counts' );
					$request->assign_hook( $hook_name );
					$request->send();
				}
				return;
			}

			if ( 'native' !== $runtime_owner || ! function_exists( 'wc_get_container' ) ) {
				return;
			}

			$fraud_methods = array(
				'wcpay_list_fraud_outcome_transactions_request' => 'get_fraud_outcome_transactions',
				'wcpay_list_fraud_outcome_transactions_summary_request' => 'get_fraud_outcome_transactions_summary',
				'wcpay_get_fraud_outcome_transactions_search_autocomplete_request' => 'get_fraud_outcome_transactions_search_autocomplete',
				'wcpay_get_fraud_outcome_transactions_export_request' => 'get_fraud_outcome_transactions_export',
			);
			$transactions_controller_class = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTransactionsRestController';
			if ( isset( $fraud_methods[ $hook_name ] ) && class_exists( $transactions_controller_class ) && class_exists( 'WP_REST_Request' ) ) {
				$controller = wc_get_container()->get( $transactions_controller_class );
				$method     = $fraud_methods[ $hook_name ];
				if ( ! is_object( $controller ) || ! method_exists( $controller, $method ) ) {
					return;
				}
				$request    = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions/hook-shape' );
				$request->set_param( 'status', 'block' );
				$request->set_param( 'page', 1 );
				$request->set_param( 'pagesize', 1 );
				$controller->$method( $request );
				return;
			}

			$api_client_class = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient';
			if ( ! class_exists( $api_client_class ) ) {
				return;
			}
			$api_client = wc_get_container()->get( $api_client_class );
			$native_api_probes = array(
				'wcpay_get_pm_promotions_request'             => array( 'get_pm_promotions', array( array( 'locale' => 'en_US', 'dismissals' => array() ) ) ),
				'wcpay_activate_pm_promotion_request'          => array( 'activate_pm_promotion', array( 'hook-shape-promotion' ) ),
				'wcpay_get_authorization_request'              => array( 'get_authorization', array( 'pi_hook_shape' ) ),
				'wcpay_list_documents_request'                 => array( 'get_documents', array( array( 'pagesize' => 1 ) ) ),
				'wcpay_validate_vat_request'                   => array( 'validate_vat', array( 'EU123456789' ) ),
				'wcpay_get_active_loan_summary_request'        => array( 'get_capital_active_loan_summary', array() ),
				'wcpay_get_loans_request'                      => array( 'get_capital_loans', array() ),
				'wcpay_get_account_capital_link'               => array( 'create_capital_link', array( 'http://localhost/hook-shape-return', 'http://localhost/hook-shape-refresh' ) ),
				'wc_pay_get_authorizations_summary'             => array( 'get_authorizations_summary', array() ),
				'wcpay_get_dispute_status_counts'              => array( 'get_dispute_status_counts', array() ),
			);
			if ( isset( $native_api_probes[ $hook_name ] ) ) {
				list( $method, $arguments ) = $native_api_probes[ $hook_name ];
				if ( is_object( $api_client ) && method_exists( $api_client, $method ) ) {
					$api_client->$method( ...$arguments );
				}
			}
		}
	);
}

$account_mode_methods = array(
	'wcpay_test_mode'            => array( 'is_test', 'is_test_mode_enabled' ),
	'wcpay_dev_mode'             => array( 'is_dev', 'is_dev_mode_enabled' ),
	'wcpay_test_mode_onboarding' => array( 'is_test_mode_onboarding', 'is_test_mode_onboarding_enabled' ),
);
foreach ( $account_mode_methods as $hook_name => $methods ) {
	$run_until_hook(
		$hook_name,
		static function () use ( $methods, $runtime_owner ): void {
			if ( 'plugin' === $runtime_owner && class_exists( 'WCPay\Core\Mode' ) ) {
				$mode   = new WCPay\Core\Mode();
				$method = $methods[0];
				if ( is_object( $mode ) && method_exists( $mode, $method ) ) {
					$mode->$method();
				}
				return;
			}

			$account_service_class = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService';
			if ( 'native' === $runtime_owner && function_exists( 'wc_get_container' ) && class_exists( $account_service_class ) ) {
				$account_service = wc_get_container()->get( $account_service_class );
				$method          = $methods[1];
				if ( is_object( $account_service ) && method_exists( $account_service, $method ) ) {
					$account_service->$method();
				}
			}
		}
	);
}

$express_product_hooks = array(
	'wcpay_payment_request_is_product_supported',
	'wcpay_payment_request_product_data',
	'wcpay_payment_request_supported_types',
	'wcpay_payment_request_total_label',
);
foreach ( $express_product_hooks as $hook_name ) {
	$run_until_hook(
		$hook_name,
		static function () use ( $hook_name, $invoke_object_method, $runtime_owner ): void {
			if ( ! class_exists( 'WC_Product_Simple' ) ) {
				return;
			}

			$product = new WC_Product_Simple();
			$product->set_name( 'Hook shape product' );
			$product->set_regular_price( '12.34' );
			$product->set_price( '12.34' );
			$had_product      = array_key_exists( 'product', $GLOBALS );
			$previous_product = $GLOBALS['product'] ?? null;
			$GLOBALS['product'] = $product;

			try {
				if ( 'plugin' === $runtime_owner && class_exists( 'WC_Payments_Express_Checkout_Button_Helper' ) && class_exists( 'WC_Payments' ) ) {
					$helper = new class( WC_Payments::get_gateway(), WC_Payments::get_account_service() ) extends WC_Payments_Express_Checkout_Button_Helper {
						public function is_product() {
							return true;
						}

						public function get_product() {
							return $GLOBALS['product'] ?? null;
						}
					};
					if ( 'wcpay_payment_request_supported_types' === $hook_name ) {
						$helper->supported_product_types();
					} elseif ( 'wcpay_payment_request_is_product_supported' === $hook_name ) {
						$invoke_object_method( $helper, 'is_product_supported' );
					} else {
						$helper->get_product_data();
					}
					return;
				}

				$service_class = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService';
				if ( 'native' === $runtime_owner && function_exists( 'wc_get_container' ) && class_exists( $service_class ) ) {
					$service = wc_get_container()->get( $service_class );
					if ( is_object( $service ) ) {
						$method = in_array( $hook_name, array( 'wcpay_payment_request_supported_types', 'wcpay_payment_request_is_product_supported' ), true )
							? 'is_product_supported'
							: 'get_product_data';
						$invoke_object_method( $service, $method );
					}
				}
			} finally {
				if ( $had_product ) {
					$GLOBALS['product'] = $previous_product;
				} else {
					unset( $GLOBALS['product'] );
				}
			}
		}
	);
}

foreach ( array( 'wc_payments_thank_you_page_bnpl_payment_method_logo_url', 'wc_payments_thank_you_page_lpm_payment_method_logo_url' ) as $hook_name ) {
	$run_until_hook(
		$hook_name,
		static function () use ( $invoke_object_method, $runtime_owner ): void {
			if ( ! class_exists( 'WC_Order' ) ) {
				return;
			}

			$order = new WC_Order();
			if ( 'plugin' === $runtime_owner && class_exists( 'WC_Payments_Order_Success_Page' ) && class_exists( 'WC_Payments' ) ) {
				$gateway = WC_Payments::get_gateway();
				$methods = is_object( $gateway ) && method_exists( $gateway, 'get_payment_methods' ) ? $gateway->get_payment_methods() : array();
				$method  = is_array( $methods ) ? ( $methods['ideal'] ?? reset( $methods ) ) : null;
				if ( is_object( $gateway ) && is_object( $method ) ) {
					$page = new WC_Payments_Order_Success_Page();
					$page->show_lpm_payment_method_name( $gateway, $method );
				}
				return;
			}

			$page_class     = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderSuccessPage';
			$registry_class = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry';
			if ( 'native' !== $runtime_owner || ! function_exists( 'wc_get_container' ) || ! class_exists( $page_class ) || ! class_exists( $registry_class ) ) {
				return;
			}

			$page        = wc_get_container()->get( $page_class );
			$registry    = wc_get_container()->get( $registry_class );
			$definitions = is_object( $registry ) && method_exists( $registry, 'get_all' ) ? $registry->get_all() : array();
			$definition  = is_array( $definitions ) ? ( $definitions['ideal'] ?? reset( $definitions ) ) : null;
			if ( is_object( $page ) && is_object( $definition ) ) {
				$invoke_object_method( $page, 'render_definition_title', array( $definition, $order, false ) );
			}
		}
	);
}

$run_until_hook(
	'wcpay_database_cache_ttl',
	static function () use ( $invoke_object_method, $runtime_owner ): void {
		$cache_contents = array(
			'data'               => array(),
			'errored'            => false,
			'consecutive_errors' => 0,
		);
		if ( 'plugin' === $runtime_owner && class_exists( 'WC_Payments' ) && method_exists( 'WC_Payments', 'get_database_cache' ) ) {
			$cache = WC_Payments::get_database_cache();
			if ( is_object( $cache ) ) {
				$invoke_object_method( $cache, 'get_ttl', array( 'wcpay_account_data', $cache_contents ) );
			}
			return;
		}

		$service_class = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService';
		if ( 'native' === $runtime_owner && function_exists( 'wc_get_container' ) && class_exists( $service_class ) ) {
			$service = wc_get_container()->get( $service_class );
			if ( is_object( $service ) ) {
				$invoke_object_method( $service, 'get_account_cache_ttl', array( $cache_contents ) );
			}
		}
	}
);

$tracking_probes = array(
	'wcpay_shopper_tracking_enabled' => array( 'should_enable_tracking', 'is_shopper_tracking_enabled', array() ),
	'wcpay_tracks_event_properties'  => array( 'tracks_record_event', 'record_user_event', array( 'hook_shape', array( 'source' => 'hook_shape' ) ) ),
);
foreach ( $tracking_probes as $hook_name => $probe ) {
	$run_until_hook(
		$hook_name,
		static function () use ( $probe, $runtime_owner ): void {
			if ( 'plugin' === $runtime_owner && class_exists( 'WC_Payments' ) && method_exists( 'WC_Payments', 'woopay_tracker' ) ) {
				$tracker = WC_Payments::woopay_tracker();
				$method  = $probe[0];
				if ( is_object( $tracker ) && method_exists( $tracker, $method ) ) {
					$tracker->$method( ...$probe[2] );
				}
				return;
			}

			$controller_class = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController';
			if ( 'native' === $runtime_owner && function_exists( 'wc_get_container' ) && class_exists( $controller_class ) ) {
				$controller = wc_get_container()->get( $controller_class );
				$method     = $probe[1];
				if ( is_object( $controller ) && method_exists( $controller, $method ) ) {
					$controller->$method( ...$probe[2] );
				}
			}
		}
	);
}

$run_until_hook(
	'wc_payments_get_onboarding_data_args',
	static function () use ( $runtime_owner ): void {
		if ( 'plugin' === $runtime_owner && class_exists( 'WC_Payments' ) ) {
			$client = WC_Payments::get_payments_api_client();
			if ( is_object( $client ) && method_exists( $client, 'get_onboarding_data' ) ) {
				$client->get_onboarding_data( false, 'http://localhost/hook-shape-return' );
			}
			return;
		}

		$client_class = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient';
		if ( 'native' === $runtime_owner && function_exists( 'wc_get_container' ) && class_exists( $client_class ) ) {
			$client = wc_get_container()->get( $client_class );
			if ( is_object( $client ) && method_exists( $client, 'initialize_onboarding' ) ) {
				$client->initialize_onboarding( false, 'http://localhost/hook-shape-return' );
			}
		}
	}
);

$run_until_hook(
	'wcpay_terminal_payment_completed_order_status',
	static function () use ( $invoke_object_method, $runtime_owner ): void {
		if ( ! class_exists( 'WC_Order' ) ) {
			return;
		}

		$order = new WC_Order();
		if ( 'plugin' === $runtime_owner && class_exists( 'WC_Payments' ) && method_exists( 'WC_Payments', 'get_order_service' ) ) {
			$order_service = WC_Payments::get_order_service();
			if ( is_object( $order_service ) && method_exists( $order_service, 'mark_terminal_payment_completed' ) ) {
				$order_service->mark_terminal_payment_completed( $order, 'pi_hook_shape', 'succeeded' );
			}
			return;
		}

		$controller_class = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsMobileRestController';
		if ( 'native' === $runtime_owner && function_exists( 'wc_get_container' ) && class_exists( $controller_class ) ) {
			$controller = wc_get_container()->get( $controller_class );
			if ( is_object( $controller ) ) {
				$invoke_object_method(
					$controller,
					'mark_terminal_payment_completed',
					array(
						$order,
						array(
							'id'       => 'pi_hook_shape',
							'status'   => 'succeeded',
							'currency' => 'usd',
						),
						'pi_hook_shape',
					)
				);
			}
		}
	}
);

$run_until_hook(
	'wcpay_create_customer_disallowed_order_statuses',
	static function () use ( $runtime_owner ): void {
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Order' ) || ! class_exists( 'WP_REST_Request' ) ) {
			return;
		}

		$order = wc_create_order();
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		try {
			$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/create_customer' );
			$request->set_param( 'order_id', $order->get_id() );

			if ( 'plugin' === $runtime_owner && class_exists( 'WC_REST_Payments_Orders_Controller' ) && class_exists( 'WC_Payments' ) ) {
				$controller = new WC_REST_Payments_Orders_Controller(
					WC_Payments::get_payments_api_client(),
					WC_Payments::get_gateway(),
					WC_Payments::get_customer_service(),
					WC_Payments::get_order_service()
				);
				$controller->create_customer( $request );
				return;
			}

			$controller_class = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsMobileRestController';
			if ( 'native' === $runtime_owner && function_exists( 'wc_get_container' ) && class_exists( $controller_class ) ) {
				$controller = wc_get_container()->get( $controller_class );
				if ( is_object( $controller ) && method_exists( $controller, 'create_customer' ) ) {
					$controller->create_customer( $request );
				}
			}
		} finally {
			$order->delete( true );
		}
	}
);

$run_until_hook(
	'wcpay_webhook_platform_checkout_order_status_changed',
	static function () use ( $runtime_owner ): void {
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Order' ) ) {
			return;
		}

		$order = wc_create_order();
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$order->update_meta_data( 'is_woopay', true );
		$order->save();

		try {
			if ( 'plugin' === $runtime_owner && class_exists( 'WCPay\WooPay\WooPay_Order_Status_Sync' ) ) {
				WCPay\WooPay\WooPay_Order_Status_Sync::send_webhook( $order->get_id(), 'pending', 'processing' );
				return;
			}

			$sync_class = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayOrderStatusSync';
			if ( 'native' === $runtime_owner && function_exists( 'wc_get_container' ) && class_exists( $sync_class ) ) {
				$sync = wc_get_container()->get( $sync_class );
				if ( is_object( $sync ) && method_exists( $sync, 'send_webhook' ) ) {
					$sync->send_webhook( $order->get_id(), 'pending', 'processing' );
				}
			}
		} finally {
			$order->delete( true );
		}
	}
);

$run_until_hook(
	'woocommerce_payments_account_refreshed',
	static function () use ( $runtime_owner, $write_object_property ): void {
		if (
			'plugin' === $runtime_owner
			&& class_exists( 'WC_Payments_Account' )
			&& class_exists( 'WC_Payments_API_Client' )
			&& class_exists( 'WCPay\Database_Cache' )
		) {
			$api_client = new class() extends WC_Payments_API_Client {
				public function __construct() {}

				public function is_server_connected(): bool {
					return true;
				}
			};
			$cache      = new class() extends WCPay\Database_Cache {
				public function get_or_add( string $key, callable $generator, callable $validate_data, bool $force_refresh = false, bool &$refreshed = false ) {
					unset( $key, $generator, $validate_data, $force_refresh );
					$refreshed = true;

					return array();
				}
			};

			$account = ( new ReflectionClass( 'WC_Payments_Account' ) )->newInstanceWithoutConstructor();
			if (
				$write_object_property( $account, 'payments_api_client', $api_client )
				&& $write_object_property( $account, 'database_cache', $cache )
			) {
				$account->refresh_account_data();
			}
			return;
		}

		$service_class = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService';
		$client_class  = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient';
		$proxy_class   = 'Automattic\WooCommerce\Proxies\LegacyProxy';
		if ( 'native' !== $runtime_owner || ! class_exists( $service_class ) || ! class_exists( $client_class ) || ! class_exists( $proxy_class ) ) {
			return;
		}

		$api_client = new class() extends Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient {
			public function is_available(): bool {
				return true;
			}

			public function get_account( string $woocommerce_store_id = '' ): array {
				unset( $woocommerce_store_id );

				return array();
			}
		};
		$proxy      = new class() extends Automattic\WooCommerce\Proxies\LegacyProxy {
			private array $options = array();

			public function call_function( $function_name, ...$parameters ) {
				switch ( $function_name ) {
					case 'get_option':
						return $this->options[ (string) ( $parameters[0] ?? '' ) ] ?? ( $parameters[1] ?? false );
					case 'update_option':
						$this->options[ (string) ( $parameters[0] ?? '' ) ] = $parameters[1] ?? null;
						return true;
					case 'time':
						return 1_700_000_000;
					case 'delete_transient':
					case 'wp_cache_delete':
						return true;
				}

				return null;
			}
		};
		$service    = new class( $api_client ) extends Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService {
			private Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient $probe_api_client;

			public function __construct( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient $api_client ) {
				$this->probe_api_client = $api_client;
			}

			protected function get_api_client(): ?Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient {
				return $this->probe_api_client;
			}
		};
		$service->init( $proxy );
		$service->refresh_account_data();
	}
);

$get_plugin_multi_currency = static function () {
	if ( function_exists( 'WC_Payments_Multi_Currency' ) ) {
		return WC_Payments_Multi_Currency();
	}

	if ( class_exists( 'WCPay\MultiCurrency\MultiCurrency' ) && method_exists( 'WCPay\MultiCurrency\MultiCurrency', 'instance' ) ) {
		return WCPay\MultiCurrency\MultiCurrency::instance();
	}

	return null;
};

$build_native_mc_fixture = static function () {
	$localization_class = 'Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyLocalizationService';
	$builder_class      = 'Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder';
	$currency_class     = 'Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency';
	$state_class        = 'Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState';
	$geolocation_class  = 'Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyGeolocationService';
	if (
		! function_exists( 'wc_get_container' )
		|| ! class_exists( $localization_class )
		|| ! class_exists( $builder_class )
		|| ! class_exists( $currency_class )
		|| ! class_exists( $state_class )
		|| ! class_exists( $geolocation_class )
	) {
		return null;
	}

	$localization  = wc_get_container()->get( $localization_class );
	$default_code  = function_exists( 'get_woocommerce_currency' ) ? strtoupper( get_woocommerce_currency() ) : 'USD';
	$selected_code = 'EUR' === $default_code ? 'USD' : 'EUR';
	$country_code  = 'EUR' === $selected_code ? 'DE' : 'US';
	$default       = new $currency_class( $localization, $default_code, 1.0, true );
	$selected      = new $currency_class( $localization, $selected_code, 1.0, false );
	$currencies    = array(
		$default_code  => $default,
		$selected_code => $selected,
	);
	$state         = new $state_class( $currencies, $currencies, $default, $selected );
	$builder       = new class( $state ) extends Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder {
		private Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState $probe_state;

		public function __construct( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState $state ) {
			$this->probe_state = $state;
		}

		public function build(): Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState {
			return $this->probe_state;
		}

		public function reset(): void {}
	};
	$geolocation   = new class( $localization, $selected_code, $country_code ) extends Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyGeolocationService {
		private string $probe_currency;
		private string $probe_country;

		public function __construct( $localization, string $currency, string $country ) {
			unset( $localization );
			$this->probe_currency = $currency;
			$this->probe_country  = $country;
		}

		public function get_currency_by_customer_location(): ?string {
			return $this->probe_currency;
		}

		public function get_country_by_customer_location(): string {
			return $this->probe_country;
		}
	};

	return array(
		'builder'      => $builder,
		'geolocation'  => $geolocation,
		'localization' => $localization,
		'state'        => $state,
	);
};

foreach ( array( 'wcpay_multi_currency_storefront_widget_css', 'wcpay_multi_currency_storefront_widget_instance', 'wcpay_multi_currency_storefront_widget_args' ) as $hook_name ) {
	$run_until_hook(
		$hook_name,
		static function () use ( $get_plugin_multi_currency, $hook_name, $runtime_owner ): void {
			if ( 'plugin' === $runtime_owner ) {
				$multi_currency = $get_plugin_multi_currency();
				if ( ! is_object( $multi_currency ) || ! class_exists( 'WCPay\MultiCurrency\StorefrontIntegration' ) ) {
					return;
				}
				$integration = method_exists( $multi_currency, 'get_storefront_integration' ) ? $multi_currency->get_storefront_integration() : null;
				if ( ! is_object( $integration ) ) {
					$integration = new WCPay\MultiCurrency\StorefrontIntegration( $multi_currency );
				}
				if ( 'wcpay_multi_currency_storefront_widget_css' === $hook_name ) {
					$integration->add_inline_css();
				} else {
					$integration->modify_breadcrumb_defaults( array( 'wrap_before' => '<nav class="woocommerce-breadcrumb">' ) );
				}
				return;
			}

			$controller_class = 'Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyStorefrontIntegrationController';
			if ( 'native' === $runtime_owner && function_exists( 'wc_get_container' ) && class_exists( $controller_class ) ) {
				$controller = wc_get_container()->get( $controller_class );
				if ( 'wcpay_multi_currency_storefront_widget_css' === $hook_name ) {
					$controller->handle_wp_enqueue_scripts();
				} else {
					$controller->handle_woocommerce_breadcrumb_defaults( array( 'wrap_before' => '<nav class="woocommerce-breadcrumb">' ) );
				}
			}
		}
	);
}

foreach ( array( 'wcpay_multi_currency_override_notice_country', 'wcpay_multi_currency_override_notice_currency_name' ) as $hook_name ) {
	$run_until_hook(
		$hook_name,
		static function () use ( $build_native_mc_fixture, $get_plugin_multi_currency, $read_object_property, $runtime_owner, $write_object_property ): void {
			if ( 'plugin' === $runtime_owner ) {
				$multi_currency = $get_plugin_multi_currency();
				if ( ! is_object( $multi_currency ) || ! method_exists( $multi_currency, 'display_geolocation_currency_update_notice' ) ) {
					return;
				}
				$simulation_params = $read_object_property( $multi_currency, 'simulation_params' );
				$write_object_property( $multi_currency, 'simulation_params', array( 'hook_shape' => true ) );
				try {
					$multi_currency->display_geolocation_currency_update_notice();
				} finally {
					$write_object_property( $multi_currency, 'simulation_params', is_array( $simulation_params ) ? $simulation_params : array() );
				}
				return;
			}

			$controller_class  = 'Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySelectedCurrencyController';
			$persistence_class = 'Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencySelectedCurrencyPersistenceService';
			$fixture           = $build_native_mc_fixture();
			if ( 'native' !== $runtime_owner || ! is_array( $fixture ) || ! class_exists( $controller_class ) || ! class_exists( $persistence_class ) ) {
				return;
			}
			$controller  = new $controller_class();
			$persistence = new $persistence_class( $fixture['builder'] );
			$controller->set_persistence_service( $persistence );
			$controller->set_geolocation_service( $fixture['geolocation'] );
			$controller->handle_wp_footer();
		}
	);
}

$run_until_hook(
	'wcpay_multi_currency_apply_charm_only_to_products',
	static function () use ( $build_native_mc_fixture, $get_plugin_multi_currency, $runtime_owner ): void {
		if ( 'plugin' === $runtime_owner ) {
			$multi_currency = $get_plugin_multi_currency();
			if ( is_object( $multi_currency ) && method_exists( $multi_currency, 'get_apply_charm_only_to_products' ) ) {
				$multi_currency->get_apply_charm_only_to_products();
			}
			return;
		}

		$service_class = 'Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyFrontendProjectionService';
		$fixture       = $build_native_mc_fixture();
		if ( 'native' === $runtime_owner && is_array( $fixture ) && class_exists( $service_class ) ) {
			$service = new $service_class( $fixture['builder'], $fixture['localization'], $fixture['geolocation'] );
			$service->get_public_config();
		}
	}
);

$mc_compatibility_probes = array(
	'wcpay_multi_currency_override_selected_currency'          => array( 'override_selected_currency', array() ),
	'wcpay_multi_currency_should_return_store_currency'        => array( 'should_return_store_currency', array() ),
	'wcpay_multi_currency_should_convert_product_price'        => array( 'should_convert_product_price', array( 'product' ) ),
	'wcpay_multi_currency_should_convert_coupon_amount'        => array( 'should_convert_coupon_amount', array( 'coupon' ) ),
	'wcpay_multi_currency_should_disable_currency_switching'   => array( 'should_disable_currency_switching', array() ),
	'wcpay_multi_currency_should_hide_widgets'                 => array( 'should_hide_widgets', array() ),
);
foreach ( $mc_compatibility_probes as $hook_name => $probe ) {
	$run_until_hook(
		$hook_name,
		static function () use ( $get_plugin_multi_currency, $probe, $runtime_owner ): void {
			$arguments = $probe[1];
			if ( array( 'product' ) === $arguments ) {
				$arguments = class_exists( 'WC_Product_Simple' ) ? array( new WC_Product_Simple() ) : array();
			} elseif ( array( 'coupon' ) === $arguments ) {
				$arguments = class_exists( 'WC_Coupon' ) ? array( new WC_Coupon() ) : array();
			}

			if ( 'plugin' === $runtime_owner ) {
				$multi_currency = $get_plugin_multi_currency();
				$compatibility  = is_object( $multi_currency ) && method_exists( $multi_currency, 'get_compatibility' )
					? $multi_currency->get_compatibility()
					: null;
				$method         = $probe[0];
				if ( is_object( $compatibility ) && method_exists( $compatibility, $method ) ) {
					$compatibility->$method( ...$arguments );
				}
				return;
			}

			$controller_class = 'Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCompatibilityController';
			if ( 'native' === $runtime_owner && function_exists( 'wc_get_container' ) && class_exists( $controller_class ) ) {
				$controller = wc_get_container()->get( $controller_class );
				$method     = $probe[0];
				if ( is_object( $controller ) && method_exists( $controller, $method ) ) {
					$controller->$method( ...$arguments );
				}
			}
		}
	);
}

$run_until_hook(
	'wcpay_multi_currency_async_price_type',
	static function () use ( $get_plugin_multi_currency, $runtime_owner ): void {
		if ( 'plugin' === $runtime_owner && class_exists( 'WCPay\MultiCurrency\AsyncPriceRenderer' ) ) {
			$multi_currency = $get_plugin_multi_currency();
			if ( is_object( $multi_currency ) ) {
				$renderer = new WCPay\MultiCurrency\AsyncPriceRenderer( $multi_currency );
				$renderer->wrap_price_with_skeleton( '$12.34', 12.34, array( 'currency' => 'USD' ), 12.34, 12.34 );
			}
			return;
		}

		$controller_class = 'Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAsyncPriceRendererController';
		if ( 'native' === $runtime_owner && function_exists( 'wc_get_container' ) && class_exists( $controller_class ) ) {
			$controller = wc_get_container()->get( $controller_class );
			$controller->handle_wc_price( '$12.34', 12.34, array( 'currency' => 'USD' ), 12.34, 12.34 );
		}
	}
);

$mc_analytics_probes = array(
	'wcpay_multi_currency_disable_filter_select_clauses'        => array( 'filter_select_clauses', 'handle_woocommerce_analytics_clauses_select', array( array( 'hook_shape_clause' ), 'orders_stats' ) ),
	'wcpay_multi_currency_filter_select_clauses'                => array( 'filter_select_clauses', 'handle_woocommerce_analytics_clauses_select', array( array( 'hook_shape_clause' ), 'orders_stats' ) ),
	'wcpay_multi_currency_disable_filter_join_clauses'          => array( 'filter_join_clauses', 'handle_woocommerce_analytics_clauses_join', array( array( 'hook_shape_clause' ), 'orders_stats' ) ),
	'wcpay_multi_currency_filter_join_clauses'                  => array( 'filter_join_clauses', 'handle_woocommerce_analytics_clauses_join', array( array( 'hook_shape_clause' ), 'orders_stats' ) ),
	'wcpay_multi_currency_disable_filter_where_clauses'         => array( 'filter_where_clauses', 'handle_woocommerce_analytics_clauses_where', array( array( 'hook_shape_clause' ) ) ),
	'wcpay_multi_currency_filter_where_clauses'                 => array( 'filter_where_clauses', 'handle_woocommerce_analytics_clauses_where', array( array( 'hook_shape_clause' ) ) ),
	'wcpay_multi_currency_disable_filter_select_orders_clauses' => array( 'filter_select_orders_clauses', 'handle_woocommerce_analytics_clauses_select_orders', array( array( 'hook_shape_clause' ) ) ),
	'wcpay_multi_currency_filter_select_orders_clauses'         => array( 'filter_select_orders_clauses', 'handle_woocommerce_analytics_clauses_select_orders', array( array( 'hook_shape_clause' ) ) ),
);
foreach ( $mc_analytics_probes as $hook_name => $probe ) {
	$run_until_hook(
		$hook_name,
		static function () use ( $get_plugin_multi_currency, $probe, $runtime_owner ): void {
			if ( 'plugin' === $runtime_owner && class_exists( 'WCPay\MultiCurrency\Analytics' ) && class_exists( 'WC_Payments' ) ) {
				$multi_currency = $get_plugin_multi_currency();
				$settings       = method_exists( 'WC_Payments', 'get_settings_service' ) ? WC_Payments::get_settings_service() : null;
				if ( is_object( $multi_currency ) && is_object( $settings ) ) {
					$analytics = new WCPay\MultiCurrency\Analytics( $multi_currency, $settings );
					$method    = $probe[0];
					$analytics->$method( ...$probe[2] );
				}
				return;
			}

			$controller_class = 'Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAnalyticsController';
			if ( 'native' !== $runtime_owner || ! function_exists( 'wc_get_container' ) || ! class_exists( $controller_class ) ) {
				return;
			}
			$controller = wc_get_container()->get( $controller_class );
			$controller->set_hpos_resolver( static fn(): bool => false );
			$controller->set_request_args_resolver( static fn(): array => array( 'currency' => 'EUR' ) );
			$controller->set_default_currency_resolver( static fn(): string => 'USD' );
			$method = $probe[1];
			$controller->$method( ...$probe[2] );
		}
	);
}

$run_until_hook(
	'wcpay_{currency}_format',
	static function () use ( $runtime_owner ): void {
		if ( 'plugin' === $runtime_owner && class_exists( 'WC_Payments' ) && method_exists( 'WC_Payments', 'get_localization_service' ) ) {
			$localization = WC_Payments::get_localization_service();
			if ( is_object( $localization ) && method_exists( $localization, 'get_currency_format' ) ) {
				$localization->get_currency_format( 'USD' );
			}
			return;
		}

		$service_class = 'Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyLocalizationService';
		if ( 'native' === $runtime_owner && function_exists( 'wc_get_container' ) && class_exists( $service_class ) ) {
			$service = wc_get_container()->get( $service_class );
			$service->get_currency_format( 'USD' );
		}
	}
);

$run_until_hook(
	'wcpay_get_add_payment_method_redirect_url',
	static function () use ( $read_object_property, $runtime_owner, $write_object_property ): void {
		$previous_post    = $_POST;
		$previous_user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$_POST['wcpay-setup-intent'] = 'seti_hook_shape';
		if ( 0 >= $previous_user_id && function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( 1 );
		}

		try {
			if (
				'plugin' === $runtime_owner
				&& class_exists( 'WC_Payments' )
				&& class_exists( 'WC_Payments_API_Client' )
				&& class_exists( 'WC_Payments_API_Setup_Intention' )
				&& class_exists( 'WC_Payments_Customer_Service' )
				&& class_exists( 'WC_Payments_Token_Service' )
			) {
				$gateway = WC_Payments::get_gateway();
				if ( ! is_object( $gateway ) || ! method_exists( $gateway, 'add_payment_method' ) ) {
					return;
				}

				$api_client = new class() extends WC_Payments_API_Client {
					public function __construct() {}

					public function send_request( WCPay\Core\Server\Request $request ) {
						unset( $request );

						return array();
					}

					public function deserialize_setup_intention_object_from_array( array $intention_array ): WC_Payments_API_Setup_Intention {
						unset( $intention_array );

						return new WC_Payments_API_Setup_Intention(
							'seti_hook_shape',
							'cus_hook_shape',
							'pm_hook_shape',
							new DateTime(),
							'succeeded',
							'setup_secret_hook_shape'
						);
					}
				};
				$customer_service = new class() extends WC_Payments_Customer_Service {
					public function __construct() {}

					public function get_customer_id_by_user_id( $user_id ) {
						unset( $user_id );

						return 'cus_hook_shape';
					}
				};
				$token_service = new class() extends WC_Payments_Token_Service {
					public function __construct() {}

					public function add_payment_method_to_user( $payment_method_id, $user ) {
						unset( $payment_method_id, $user );
					}
				};

				$gateway_api      = $read_object_property( $gateway, 'payments_api_client' );
				$gateway_customer = $read_object_property( $gateway, 'customer_service' );
				$gateway_token    = $read_object_property( $gateway, 'token_service' );
				$wc_payments      = new ReflectionClass( 'WC_Payments' );
				$api_property     = $wc_payments->getProperty( 'api_client' );
				$api_property->setAccessible( true );
				$static_api = $api_property->getValue();

				$write_object_property( $gateway, 'payments_api_client', $api_client );
				$write_object_property( $gateway, 'customer_service', $customer_service );
				$write_object_property( $gateway, 'token_service', $token_service );
				$api_property->setValue( null, $api_client );
				try {
					$gateway->add_payment_method();
				} finally {
					$write_object_property( $gateway, 'payments_api_client', $gateway_api );
					$write_object_property( $gateway, 'customer_service', $gateway_customer );
					$write_object_property( $gateway, 'token_service', $gateway_token );
					$api_property->setValue( null, $static_api );
				}
				return;
			}

			$gateway_class  = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway';
			$client_class   = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient';
			$customer_class = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService';
			$token_class    = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService';
			if (
				'native' !== $runtime_owner
				|| ! function_exists( 'WC' )
				|| ! class_exists( $gateway_class )
				|| ! class_exists( $client_class )
				|| ! class_exists( $customer_class )
				|| ! class_exists( $token_class )
			) {
				return;
			}

			$gateways = WC()->payment_gateways()->payment_gateways();
			$gateway  = null;
			foreach ( $gateways as $candidate ) {
				if ( $candidate instanceof $gateway_class ) {
					$gateway = $candidate;
					break;
				}
			}
			if ( ! is_object( $gateway ) ) {
				return;
			}

			$api_client = new class() extends Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient {
				public function get_setup_intention( string $setup_intent_id ): array {
					return array(
						'id'             => $setup_intent_id,
						'status'         => 'succeeded',
						'customer'       => 'cus_hook_shape',
						'payment_method' => 'pm_hook_shape',
					);
				}
			};
			$customer_service = new class() extends Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService {
				public function get_customer_id_by_user_id( ?int $user_id ): ?string {
					unset( $user_id );

					return 'cus_hook_shape';
				}
			};
			$token_service = new class() extends Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService {
				public function get_or_create_token_for_user( string $payment_method_id, int $user_id ): ?WC_Payment_Token {
					unset( $user_id );
					$token = new WC_Payment_Token_CC();
					$token->set_token( $payment_method_id );

					return $token;
				}
			};
			$gateway_api      = $read_object_property( $gateway, 'api_client' );
			$gateway_customer = $read_object_property( $gateway, 'customer_service' );
			$gateway_token    = $read_object_property( $gateway, 'token_service' );
			$write_object_property( $gateway, 'api_client', $api_client );
			$write_object_property( $gateway, 'customer_service', $customer_service );
			$write_object_property( $gateway, 'token_service', $token_service );
			try {
				$gateway->add_payment_method();
			} finally {
				$write_object_property( $gateway, 'api_client', $gateway_api );
				$write_object_property( $gateway, 'customer_service', $gateway_customer );
				$write_object_property( $gateway, 'token_service', $gateway_token );
			}
		} finally {
			$_POST = $previous_post;
			if ( function_exists( 'wp_set_current_user' ) ) {
				wp_set_current_user( $previous_user_id );
			}
		}
	}
);

$payment_requires_action_probe = static function () use ( $read_object_property, $runtime_owner, $write_object_property ): void {
	if ( ! class_exists( 'WC_Order' ) ) {
		return;
	}

	if (
		'plugin' === $runtime_owner
		&& class_exists( 'WC_Payments' )
		&& class_exists( 'WC_Payments_API_Client' )
		&& class_exists( 'WC_Payments_API_Payment_Intention' )
		&& class_exists( 'WCPay\Payment_Information' )
		&& class_exists( 'WCPay\Constants\Payment_Initiated_By' )
	) {
		$gateway = WC_Payments::get_gateway();
		if ( ! is_object( $gateway ) || ! method_exists( $gateway, 'process_payment_for_order' ) ) {
			return;
		}

		$api_client = new class() extends WC_Payments_API_Client {
			public function __construct() {}

			public function send_request( WCPay\Core\Server\Request $request ) {
				unset( $request );

				return array();
			}

			public function deserialize_payment_intention_object_from_array( array $intention_array ) {
				unset( $intention_array );
				$charge = new class() {
					public function get_id(): string {
						return 'ch_hook_shape';
					}
				};

				return new class( $charge ) extends WC_Payments_API_Payment_Intention {
					private object $probe_charge;

					public function __construct( object $charge ) {
						$this->probe_charge = $charge;
					}

					public function get_id() {
						return 'pi_hook_shape';
					}

					public function get_status() {
						return 'requires_action';
					}

					public function get_charge() {
						return $this->probe_charge;
					}

					public function get_client_secret() {
						return 'pi_hook_shape_secret';
					}

					public function get_currency() {
						return 'USD';
					}

					public function get_next_action() {
						return array();
					}

					public function get_processing() {
						return array();
					}

					public function get_payment_method_id() {
						return 'pm_hook_shape';
					}
				};
			}
		};
		$order = new WC_Order();
		$order->set_total( 12.34 );
		$order->set_currency( 'USD' );
		$order->save();
		$payment_information = new WCPay\Payment_Information(
			'pm_hook_shape',
			$order,
			null,
			null,
			WCPay\Constants\Payment_Initiated_By::MERCHANT(),
			null,
			null,
			'',
			'card',
			'cus_hook_shape'
		);

		$gateway_api  = $read_object_property( $gateway, 'payments_api_client' );
		$wc_payments  = new ReflectionClass( 'WC_Payments' );
		$api_property = $wc_payments->getProperty( 'api_client' );
		$api_property->setAccessible( true );
		$static_api = $api_property->getValue();
		$write_object_property( $gateway, 'payments_api_client', $api_client );
		$api_property->setValue( null, $api_client );
		try {
			$gateway->process_payment_for_order( null, $payment_information );
		} finally {
			$write_object_property( $gateway, 'payments_api_client', $gateway_api );
			$api_property->setValue( null, $static_api );
		}
		return;
	}

	$gateway_class = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway';
	$outcome_class = 'Automattic\WooCommerce\Internal\Payments\PaymentOutcome';
	if ( 'native' !== $runtime_owner || ! function_exists( 'WC' ) || ! class_exists( $gateway_class ) || ! class_exists( $outcome_class ) ) {
		return;
	}

	$gateways = WC()->payment_gateways()->payment_gateways();
	$gateway  = null;
	foreach ( $gateways as $candidate ) {
		if ( $candidate instanceof $gateway_class ) {
			$gateway = $candidate;
			break;
		}
	}
	if ( ! is_object( $gateway ) ) {
		return;
	}

	$order = new WC_Order();
	$order->set_status( 'failed' );
	$order->set_currency( 'USD' );
	$outcome = new $outcome_class( $outcome_class::STATUS_REQUIRES_CUSTOMER_ACTION );
	$method  = new ReflectionMethod( $gateway, 'maybe_handle_subscription_customer_action_required' );
	$method->setAccessible( true );
	$method->invoke( $gateway, $order, $outcome );
};

if ( 'native' === $runtime_owner ) {
	$run_product_probe_group(
		'WooPayments customer-action-required surrounding path',
		array( 'woocommerce_woocommerce_payments_payment_requires_action' ),
		$payment_requires_action_probe
	);
} else {
	$run_until_hook( 'woocommerce_woocommerce_payments_payment_requires_action', $payment_requires_action_probe );
}

$run_product_probe_group(
	'wcpay_metadata_from_order',
	array( 'wcpay_metadata_from_order' ),
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

$run_product_probe_group(
	'wcpay_payment_fields_js_config',
	array( 'wcpay_payment_fields_js_config', 'wc_payments_account_id_for_intent_confirmation' ),
	static function () use ( $runtime_owner ): void {
		if ( 'plugin' === $runtime_owner && class_exists( 'WC_Payments' ) && method_exists( 'WC_Payments', 'get_wc_payments_checkout' ) ) {
			$checkout = WC_Payments::get_wc_payments_checkout();
			if ( is_object( $checkout ) && method_exists( $checkout, 'get_payment_fields_js_config' ) ) {
				$checkout->get_payment_fields_js_config();
			}
			return;
		}

		if ( 'native' === $runtime_owner && function_exists( 'wc_get_container' ) && class_exists( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsCheckoutBridge' ) ) {
			$bridge = wc_get_container()->get( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsCheckoutBridge' );
			if ( is_object( $bridge ) && method_exists( $bridge, 'get_payment_fields_js_config' ) ) {
				$bridge->get_payment_fields_js_config();
			}
		}
	}
);

$event_body = array(
	'id'   => '',
	'type' => 'hook_shape.probe',
	'data' => array(
		'object' => array(
			'id' => '',
		),
	),
);

$run_product_probe_group(
	'WooPayments webhook processing surrounding path',
	array( 'woocommerce_payments_before_webhook_delivery', 'woocommerce_payments_after_webhook_delivery' ),
	static function () use ( $event_body, $runtime_owner ): void {
		if ( 'plugin' === $runtime_owner && class_exists( 'WC_Payments' ) ) {
			$reflection = new ReflectionClass( 'WC_Payments' );
			if ( ! $reflection->hasProperty( 'webhook_processing_service' ) ) {
				return;
			}

			$property = $reflection->getProperty( 'webhook_processing_service' );
			$property->setAccessible( true );
			$service = $property->getValue();
			if ( is_object( $service ) && method_exists( $service, 'process' ) ) {
				$service->process( $event_body );
			}
			return;
		}

		if (
			'native' === $runtime_owner
			&& function_exists( 'wc_get_container' )
			&& class_exists( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsEventIngestor' )
		) {
			$ingestor = wc_get_container()->get( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsEventIngestor' );
			if ( is_object( $ingestor ) && method_exists( $ingestor, 'process' ) ) {
				$ingestor->process( $event_body );
			}
		}
	}
);

$run_product_probe_group(
	'WooPay signed-request surrounding path',
	array( 'wcpay_woopay_is_signed_with_blog_token' ),
	static function () use ( $runtime_owner ): void {
		$session_class = 'WCPay\WooPay\WooPay_Session';
		if ( 'plugin' === $runtime_owner && class_exists( $session_class ) && method_exists( $session_class, 'has_valid_request_signature' ) ) {
			$session_class::has_valid_request_signature();
			return;
		}

		if (
			'native' !== $runtime_owner
			|| ! function_exists( 'wc_get_container' )
			|| ! class_exists( 'WP_REST_Request' )
			|| ! class_exists( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsWooPaySessionController' )
		) {
			return;
		}

		$controller = wc_get_container()->get( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsWooPaySessionController' );
		if ( ! is_object( $controller ) || ! method_exists( $controller, 'check_permission' ) ) {
			return;
		}

		$auth_instance = null;
		$status         = null;
		$type           = null;
		$status_before  = null;
		$type_before    = null;
		$auth_class     = 'Automattic\\Jetpack\\Connection\\Rest_Authentication';

		if ( class_exists( $auth_class ) && method_exists( $auth_class, 'init' ) ) {
			$auth_instance   = $auth_class::init();
			$auth_reflection = new ReflectionClass( $auth_class );
			if ( $auth_reflection->hasProperty( 'rest_authentication_status' ) && $auth_reflection->hasProperty( 'rest_authentication_type' ) ) {
				$status = $auth_reflection->getProperty( 'rest_authentication_status' );
				$type   = $auth_reflection->getProperty( 'rest_authentication_type' );
				$status->setAccessible( true );
				$type->setAccessible( true );
				$status_before = $status->getValue( $auth_instance );
				$type_before   = $type->getValue( $auth_instance );
				$status->setValue( $auth_instance, true );
				$type->setValue( $auth_instance, 'blog' );
			}
		}

		try {
			$request = new WP_REST_Request( 'GET', '/payments/woopay/session' );
			$request->set_header( 'User-Agent', 'WooPay' );
			$controller->check_permission( $request );
		} finally {
			if ( null !== $auth_instance && null !== $status && null !== $type ) {
				$status->setValue( $auth_instance, $status_before );
				$type->setValue( $auth_instance, $type_before );
			}
		}
	}
);

$run_until_hook(
	'wcpay_is_woopay_store_api_request',
	static function () use ( $runtime_owner ): void {
		if ( 'plugin' === $runtime_owner && class_exists( 'WC_Payments' ) && method_exists( 'WC_Payments', 'get_gateway' ) && function_exists( 'wc_get_orders' ) && function_exists( 'WC' ) ) {
			$gateway = WC_Payments::get_gateway();
			if ( ! is_object( $gateway ) || ! method_exists( $gateway, 'process_payment' ) ) {
				return;
			}

			$orders = wc_get_orders(
				array(
					'limit'   => 10,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'objects',
				)
			);
			$order  = null;
			foreach ( $orders as $candidate ) {
				if ( ! is_object( $candidate ) || ! method_exists( $candidate, 'get_id' ) ) {
					continue;
				}

				$phone = method_exists( $candidate, 'get_billing_phone' ) ? (string) $candidate->get_billing_phone() : '';
				if ( 20 >= strlen( $phone ) ) {
					$order = $candidate;
					break;
				}
			}

			if ( null === $order ) {
				return;
			}

			$woocommerce      = WC();
			$had_session      = is_object( $woocommerce ) && property_exists( $woocommerce, 'session' );
			$previous_session = $had_session ? $woocommerce->session : null;
			if ( is_object( $woocommerce ) && ! $previous_session ) {
				$woocommerce->session = (object) array();
			}

			try {
				$gateway->process_payment( $order->get_id() );
			} finally {
				if ( is_object( $woocommerce ) ) {
					if ( $had_session ) {
						$woocommerce->session = $previous_session;
					} else {
						unset( $woocommerce->session );
					}
				}
			}
			return;
		}

		if ( 'native' !== $runtime_owner || ! class_exists( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\NativeWooPaymentsGateway' ) || ! function_exists( 'WC' ) ) {
			return;
		}

		$woocommerce = WC();
		if ( ! is_object( $woocommerce ) || ! method_exists( $woocommerce, 'payment_gateways' ) ) {
			return;
		}

		$gateway_manager = $woocommerce->payment_gateways();
		$gateways        = is_object( $gateway_manager ) && method_exists( $gateway_manager, 'payment_gateways' ) ? $gateway_manager->payment_gateways() : array();
		$gateway_class   = 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\NativeWooPaymentsGateway';
		$gateway         = null;
		foreach ( $gateways as $candidate ) {
			if ( $candidate instanceof $gateway_class ) {
				$gateway = $candidate;
				break;
			}
		}

		if ( null === $gateway ) {
			return;
		}

		$method = new ReflectionMethod( $gateway_class, 'get_fraud_prevention_error_message' );
		$method->setAccessible( true );
		$method->invoke( $gateway, true );
	}
);

$probe_object = static function ( string $class_name, array $probe_data = array() ) {
	try {
		if ( class_exists( $class_name ) ) {
			return new $class_name();
		}
	} catch ( Throwable $throwable ) {
		// Fall through to a stable probe object.
	}

	return (object) $probe_data;
};

$probe_request = static function ( string $legacy_class, string $native_class ) {
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
		// Fall through to a stable probe object.
	}

	return (object) array( 'request' => 'probe' );
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

$probe_order     = $probe_object( 'WC_Order', array( 'type' => 'order' ) );
$probe_product   = $probe_object( 'WC_Product_Simple', array( 'type' => 'simple' ) );
$probe_coupon    = $probe_object( 'WC_Coupon', array( 'type' => 'coupon' ) );
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

// Hooks not reached by the surrounding-path probes above are driven through the WordPress hook boundary.
$runtime_hook_probes = array(
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
		$probe_request(
			'WCPay\\Core\\Server\\Request\\List_Transactions',
			'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsTransactionsListRequest'
		),
	),
	'wcpay_list_disputes_request'                                    => array(
		$probe_request(
			'WCPay\\Core\\Server\\Request\\List_Disputes',
			'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsDisputesListRequest'
		),
	),
	'wcpay_list_deposits_request'                                    => array(
		$probe_request(
			'WCPay\\Core\\Server\\Request\\List_Deposits',
			'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsDepositsListRequest'
		),
	),
	'wcpay_list_authorizations_request'                              => array(
		$probe_request(
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
		$probe_order,
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
	'wc_payments_account_id_for_intent_confirmation'                 => array( '' ),
	'wcpay_is_woopay_store_api_request'                              => array( false ),
	'wcpay_payment_request_is_product_supported'                     => array( true, $probe_product ),
	'wcpay_payment_request_product_data'                             => array(
		array(
			'currency'     => 'usd',
			'displayItems' => array(),
			'total'        => array(),
		),
		$probe_product,
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
		$probe_order,
		'pi_hook_shape',
		'pm_hook_shape',
		'cus_hook_shape',
		'ch_hook_shape',
		'USD',
	),
	'wcpay_multi_currency_override_selected_currency'                => array( false ),
	'wcpay_multi_currency_should_return_store_currency'              => array( false ),
	'wcpay_multi_currency_should_convert_product_price'              => array( true, $probe_product ),
	'wcpay_multi_currency_should_convert_coupon_amount'              => array( true, $probe_coupon ),
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

$generic_request_probe = $probe_request(
	'WCPay\Core\Server\Request\Generic',
	'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsApiRequest'
);
$runtime_hook_probes  += array(
	'wcpay_list_fraud_outcome_transactions_request'                 => array( $generic_request_probe ),
	'wcpay_list_fraud_outcome_transactions_summary_request'         => array( $generic_request_probe ),
	'wcpay_get_fraud_outcome_transactions_search_autocomplete_request' => array( $generic_request_probe ),
	'wcpay_get_fraud_outcome_transactions_export_request'           => array( $generic_request_probe ),
	'wcpay_get_pm_promotions_request'                               => array( $generic_request_probe ),
	'wcpay_activate_pm_promotion_request'                            => array( $generic_request_probe ),
	'wcpay_get_authorization_request'                               => array( $generic_request_probe ),
	'wcpay_list_documents_request'                                  => array( $generic_request_probe ),
	'wcpay_validate_vat_request'                                    => array( $generic_request_probe ),
	'wcpay_get_active_loan_summary_request'                          => array( $generic_request_probe ),
	'wcpay_get_loans_request'                                       => array( $generic_request_probe ),
	'wcpay_get_account_capital_link'                                => array( $generic_request_probe ),
	'wc_pay_get_authorizations_summary'                              => array( $generic_request_probe ),
	'wcpay_get_dispute_status_counts'                               => array( $generic_request_probe ),
	'wc_payments_thank_you_page_bnpl_payment_method_logo_url'       => array( 'https://example.test/logo.svg', 'klarna' ),
	'wc_payments_thank_you_page_lpm_payment_method_logo_url'        => array( 'https://example.test/logo.svg', 'ideal' ),
	'wcpay_webhook_platform_checkout_order_status_changed'          => array( 123, 'processing' ),
	'wcpay_multi_currency_storefront_widget_css'                    => array( '.widget { display: block; }' ),
	'wcpay_multi_currency_storefront_widget_instance'               => array( array() ),
	'wcpay_multi_currency_storefront_widget_args'                   => array( array() ),
	'wcpay_multi_currency_override_notice_country'                  => array( 'United States' ),
	'wcpay_multi_currency_override_notice_currency_name'            => array( 'US dollar' ),
	'wcpay_multi_currency_apply_charm_only_to_products'             => array( true ),
);

$runtime_action_hooks = array_fill_keys(
	array(
		'woocommerce_payments_account_refreshed',
		'woocommerce_payments_before_webhook_delivery',
		'woocommerce_payments_after_webhook_delivery',
		'woocommerce_woocommerce_payments_payment_requires_action',
		'wcpay_webhook_platform_checkout_order_status_changed',
	),
	true
);

$missing_runtime_probes = array_diff( woopayments_hook_shape_required_hooks(), array_keys( $runtime_hook_probes ) );
foreach ( $missing_runtime_probes as $hook_name ) {
	$errors[] = 'missing runtime hook probe: ' . $hook_name;
}

foreach ( woopayments_hook_shape_required_hooks() as $hook_name ) {
	if ( isset( $captured[ $hook_name ] ) || ! array_key_exists( $hook_name, $runtime_hook_probes ) ) {
		continue;
	}

	$runtime_hook_name = woopayments_hook_shape_runtime_hook_name( $hook_name );
	$hook_args         = $runtime_hook_probes[ $hook_name ];
	$is_action         = isset( $runtime_action_hooks[ $hook_name ] );
	$run_diagnostic_probe(
		$hook_name,
		static function () use ( $hook_args, $is_action, $runtime_hook_name ): void {
			if ( $is_action ) {
				do_action( $runtime_hook_name, ...$hook_args );
				return;
			}

			apply_filters( $runtime_hook_name, ...$hook_args );
		}
	);
}

foreach ( woopayments_hook_shape_required_hooks() as $hook_name ) {
	if ( ! isset( $captured[ $hook_name ] ) ) {
		$errors[] = 'runtime hook probe did not reach boundary: ' . $hook_name;
	}
}

ksort( $captured, SORT_STRING );

woopayments_hook_shape_emit_json(
	array(
		'schema'         => 'woopayments_hook_shape_capture.v1',
		'role'           => $role,
		'runtime_owner'  => $runtime_owner,
		'site_url'       => function_exists( 'home_url' ) ? home_url( '/' ) : '',
		'required_hooks' => woopayments_hook_shape_required_hooks(),
		'hooks'          => $captured,
		'preconditions'  => woopayments_hook_shape_preconditions(),
		'errors'         => $errors,
	)
);
