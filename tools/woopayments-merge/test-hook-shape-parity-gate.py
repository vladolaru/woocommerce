#!/usr/bin/env python3
"""Focused regression checks for the hook-shape parity gate harness."""

from __future__ import annotations

import json
import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/hook-shape-parity.sh"
DRIVER = REPO / "tools/woopayments-merge/hook-shape-parity.php"
VERIFY = REPO / "tools/woopayments-merge/verify.sh"

REF_WP = "docker exec -i wcpay_wp_default wp --allow-root"
TARGET_WP = "docker exec -i target-cli-1 wp --allow-root --user=1"


REQUIRED_HOOKS = [
    "wcpay_api_request_headers",
    "wcpay_api_request_params",
    "wcpay_api_request_response",
    "wcpay_list_fraud_outcome_transactions_request",
    "wcpay_list_fraud_outcome_transactions_summary_request",
    "wcpay_get_fraud_outcome_transactions_search_autocomplete_request",
    "wcpay_get_fraud_outcome_transactions_export_request",
    "wcpay_get_pm_promotions_request",
    "wcpay_activate_pm_promotion_request",
    "wcpay_get_authorization_request",
    "wcpay_list_documents_request",
    "wcpay_validate_vat_request",
    "wcpay_get_active_loan_summary_request",
    "wcpay_get_loans_request",
    "wcpay_get_account_capital_link",
    "wc_pay_get_authorizations_summary",
    "wcpay_get_dispute_status_counts",
    "wcpay_list_transactions_request",
    "wcpay_list_disputes_request",
    "wcpay_list_deposits_request",
    "wcpay_list_authorizations_request",
    "wcpay_metadata_from_order",
    "wcpay_payment_fields_js_config",
    "wc_payments_thank_you_page_bnpl_payment_method_logo_url",
    "wc_payments_thank_you_page_lpm_payment_method_logo_url",
    "wc_payments_account_id_for_intent_confirmation",
    "wcpay_is_woopay_store_api_request",
    "wcpay_payment_request_is_product_supported",
    "wcpay_payment_request_product_data",
    "wcpay_payment_request_supported_types",
    "wcpay_payment_request_total_label",
    "wcpay_test_mode",
    "wcpay_dev_mode",
    "wcpay_test_mode_onboarding",
    "wcpay_database_cache_ttl",
    "wcpay_get_add_payment_method_redirect_url",
    "wcpay_terminal_payment_completed_order_status",
    "wcpay_create_customer_disallowed_order_statuses",
    "wcpay_shopper_tracking_enabled",
    "wcpay_tracks_event_properties",
    "wcpay_woopay_is_signed_with_blog_token",
    "wcpay_webhook_platform_checkout_order_status_changed",
    "wc_payments_get_onboarding_data_args",
    "woocommerce_payments_account_refreshed",
    "woocommerce_payments_before_webhook_delivery",
    "woocommerce_payments_after_webhook_delivery",
    "woocommerce_woocommerce_payments_payment_requires_action",
    "wcpay_multi_currency_storefront_widget_css",
    "wcpay_multi_currency_storefront_widget_instance",
    "wcpay_multi_currency_storefront_widget_args",
    "wcpay_multi_currency_override_notice_country",
    "wcpay_multi_currency_override_notice_currency_name",
    "wcpay_multi_currency_apply_charm_only_to_products",
    "wcpay_multi_currency_override_selected_currency",
    "wcpay_multi_currency_should_return_store_currency",
    "wcpay_multi_currency_should_convert_product_price",
    "wcpay_multi_currency_should_convert_coupon_amount",
    "wcpay_multi_currency_should_disable_currency_switching",
    "wcpay_multi_currency_should_hide_widgets",
    "wcpay_multi_currency_async_price_type",
    "wcpay_multi_currency_disable_filter_select_clauses",
    "wcpay_multi_currency_filter_select_clauses",
    "wcpay_multi_currency_disable_filter_join_clauses",
    "wcpay_multi_currency_filter_join_clauses",
    "wcpay_multi_currency_disable_filter_where_clauses",
    "wcpay_multi_currency_filter_where_clauses",
    "wcpay_multi_currency_disable_filter_select_orders_clauses",
    "wcpay_multi_currency_filter_select_orders_clauses",
    "wcpay_{currency}_format",
]

PRIORITY_SURROUNDING_PATH_HOOKS = [
    "wcpay_api_request_headers",
    "wcpay_api_request_params",
    "wcpay_api_request_response",
    "wcpay_list_transactions_request",
    "wcpay_list_disputes_request",
    "wcpay_list_deposits_request",
    "wcpay_list_authorizations_request",
    "woocommerce_payments_before_webhook_delivery",
    "woocommerce_payments_after_webhook_delivery",
    "wcpay_woopay_is_signed_with_blog_token",
    "wc_payments_account_id_for_intent_confirmation",
    "wcpay_is_woopay_store_api_request",
]

EXTENSION_PRODUCT_RUNTIME = r"""
class HookShapeFakeApiClient {
	public function get_disputes( array $filters = array() ) {
		$params   = apply_filters( 'wcpay_api_request_params', $filters, 'disputes', 'GET' );
		$headers  = apply_filters( 'wcpay_api_request_headers', array( 'Content-Type' => 'application/json' ) );
		$response = apply_filters(
			'pre_http_request',
			false,
			array( 'headers' => $headers ),
			'https://public-api.wordpress.com/wpcom/v2/sites/1/wcpay/disputes?' . http_build_query( $params )
		);

		return apply_filters(
			'wcpay_api_request_response',
			$response,
			'GET',
			'https://public-api.wordpress.com/wpcom/v2/sites/1/wcpay/disputes',
			'disputes'
		);
	}
}

class HookShapeFakeWebhookService {
	public function process( array $event ) {
		do_action( 'woocommerce_payments_before_webhook_delivery', $event['type'], $event );
		do_action( 'woocommerce_payments_after_webhook_delivery', $event['type'], $event );
	}
}

class HookShapeFakeCheckout {
	public function get_payment_fields_js_config() {
		$config = array(
			'accountIdForIntentConfirmation' => apply_filters( 'wc_payments_account_id_for_intent_confirmation', '' ),
		);

		return apply_filters( 'wcpay_payment_fields_js_config', $config );
	}
}

class HookShapeFakeGateway {
	public function process_payment( $order_id ) {
		unset( $order_id );
		try {
			apply_filters( 'wcpay_is_woopay_store_api_request', false );
		} catch ( Exception $exception ) {
			throw new RuntimeException( 'Payment failure handling must not catch the probe sentinel.', 0, $exception );
		}
	}
}

class HookShapeFakeOrder {
	public function get_id() {
		return 123;
	}
}

class WC_Payments {
	private static $webhook_processing_service;

	public static function boot_hook_shape_runtime() {
		self::$webhook_processing_service = new HookShapeFakeWebhookService();
	}

	public static function get_payments_api_client() {
		return new HookShapeFakeApiClient();
	}

	public static function get_wc_payments_checkout() {
		return new HookShapeFakeCheckout();
	}

	public static function get_gateway() {
		return new HookShapeFakeGateway();
	}
}

eval( <<<'PHP'
namespace WCPay\WooPay;

class WooPay_Session {
	public static function has_valid_request_signature() {
		return \apply_filters( 'wcpay_woopay_is_signed_with_blog_token', true );
	}
}
PHP );

function wc_get_orders( $args ) {
	unset( $args );
	return array( new HookShapeFakeOrder() );
}

function WC() {
	static $woocommerce;
	if ( null === $woocommerce ) {
		$woocommerce = (object) array( 'session' => (object) array() );
	}
	return $woocommerce;
}

eval( <<<'PHP'
namespace WCPay\Core\Server\Request;

abstract class HookShapeFakeListRequest {
	abstract protected function hook_name(): string;

	public static function create() {
		return new static();
	}

	public function send() {
		return \apply_filters( $this->hook_name(), $this );
	}
}

class List_Transactions extends HookShapeFakeListRequest {
	protected function hook_name(): string { return 'wcpay_list_transactions_request'; }
}
class List_Disputes extends HookShapeFakeListRequest {
	protected function hook_name(): string { return 'wcpay_list_disputes_request'; }
}
class List_Deposits extends HookShapeFakeListRequest {
	protected function hook_name(): string { return 'wcpay_list_deposits_request'; }
}
class List_Authorizations extends HookShapeFakeListRequest {
	protected function hook_name(): string { return 'wcpay_list_authorizations_request'; }
}
PHP );

WC_Payments::boot_hook_shape_runtime();
"""

NATIVE_PRODUCT_RUNTIME = r"""
class WP_REST_Request {
	private $params = array();
	private $headers = array();

	public function __construct( $method = 'GET', $route = '' ) {
		unset( $method, $route );
	}

	public function set_param( $name, $value ) {
		$this->params[ $name ] = $value;
	}

	public function get_param( $name ) {
		return $this->params[ $name ] ?? null;
	}

	public function set_header( $name, $value ) {
		$this->headers[ strtolower( str_replace( '-', '_', $name ) ) ] = $value;
	}

	public function get_header( $name ) {
		return $this->headers[ strtolower( str_replace( '-', '_', $name ) ) ] ?? '';
	}
}

eval( <<<'PHP'
namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

abstract class HookShapeFakeListRequest {
	public static function register_legacy_alias(): void {}
	public static function from_rest_request( \WP_REST_Request $request ) { unset( $request ); return new static(); }
	public static function from_params( array $params ) { unset( $params ); return new static(); }
	public function get_params(): array { return array(); }
}

class WooPaymentsTransactionsListRequest extends HookShapeFakeListRequest {}
class WooPaymentsDisputesListRequest extends HookShapeFakeListRequest {}
class WooPaymentsDepositsListRequest extends HookShapeFakeListRequest {}
class WooPaymentsAuthorizationsListRequest extends HookShapeFakeListRequest {}

class WooPaymentsTransactionsRestController {
	public function get_transactions( \WP_REST_Request $request ) {
		return \apply_filters( 'wcpay_list_transactions_request', WooPaymentsTransactionsListRequest::from_rest_request( $request ) );
	}
}
class WooPaymentsDisputesRestController {
	public function get_disputes( \WP_REST_Request $request ) {
		return \apply_filters( 'wcpay_list_disputes_request', WooPaymentsDisputesListRequest::from_rest_request( $request ) );
	}
}
class WooPaymentsDepositsRestController {
	public function get_deposits( \WP_REST_Request $request ) {
		return \apply_filters( 'wcpay_list_deposits_request', WooPaymentsDepositsListRequest::from_rest_request( $request ) );
	}
}
class WooPaymentsAuthorizationsRestController {
	public function get_authorizations( \WP_REST_Request $request ) {
		return \apply_filters( 'wcpay_list_authorizations_request', WooPaymentsAuthorizationsListRequest::from_rest_request( $request ) );
	}
}

class WooPaymentsEventIngestor {
	public function process( array $event ): void {
		\do_action( 'woocommerce_payments_before_webhook_delivery', $event['type'], $event );
		\do_action( 'woocommerce_payments_after_webhook_delivery', $event['type'], $event );
	}
}

class WooPaymentsCheckoutBridge {
	public function get_payment_fields_js_config(): array {
		$config = array(
			'accountIdForIntentConfirmation' => \apply_filters( 'wc_payments_account_id_for_intent_confirmation', '' ),
		);
		return \apply_filters( 'wcpay_payment_fields_js_config', $config );
	}
}

class WooPaymentsWooPaySessionController {
	public function check_permission( \WP_REST_Request $request ) {
		unset( $request );
		return \apply_filters( 'wcpay_woopay_is_signed_with_blog_token', true );
	}
}

class NativeWooPaymentsGateway {
	private function get_fraud_prevention_error_message( bool $is_checkout ): string {
		if ( $is_checkout ) {
			\apply_filters( 'wcpay_is_woopay_store_api_request', false );
		}
		return '';
	}
}

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api;

class WooPaymentsApiClient {
	public function get_disputes( array $filters = array() ): array {
		$params   = \apply_filters( 'wcpay_api_request_params', $filters, 'disputes', 'GET' );
		$headers  = \apply_filters( 'wcpay_api_request_headers', array( 'Content-Type' => 'application/json' ) );
		$response = \apply_filters(
			'pre_http_request',
			false,
			array( 'headers' => $headers ),
			'https://public-api.wordpress.com/wpcom/v2/sites/1/wcpay/disputes?' . http_build_query( $params )
		);
		return \apply_filters(
			'wcpay_api_request_response',
			$response,
			'GET',
			'https://public-api.wordpress.com/wpcom/v2/sites/1/wcpay/disputes',
			'disputes'
		);
	}
}
PHP );

class HookShapeFakeNativeGatewayManager {
	public function payment_gateways() {
		return array( new Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway() );
	}
}

class HookShapeFakeNativeWooCommerce {
	public function payment_gateways() {
		return new HookShapeFakeNativeGatewayManager();
	}
}

class HookShapeFakeContainer {
	public function get( $class_name ) {
		return new $class_name();
	}
}

function WC() {
	return new HookShapeFakeNativeWooCommerce();
}

function wc_get_container() {
	return new HookShapeFakeContainer();
}
"""


def run_gate(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(SCRIPT), *args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def run_driver_with_offline_hook_runtime(
    product_runtime: str = "",
    runtime_args: list[str] | None = None,
) -> tuple[dict, list[str]]:
    runtime_args = runtime_args or ["--role", "offline"]
    runtime_args_json = json.dumps(json.dumps(runtime_args))
    with tempfile.TemporaryDirectory(prefix="hook-shape-parity-runtime-") as tmp:
        wrapper = Path(tmp) / "offline-hook-runtime.php"
        wrapper.write_text(
            """<?php
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['args'] = json_decode( """
            + runtime_args_json
            + """, true );
$GLOBALS['hook_shape_callbacks'] = array();
$GLOBALS['hook_shape_dispatches'] = array();

function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['hook_shape_callbacks'][ $hook_name ][ $priority ][] = array(
		'callback'      => $callback,
		'accepted_args' => $accepted_args,
	);
	return true;
}

function remove_filter( $hook_name, $callback, $priority = 10 ) {
	if ( ! isset( $GLOBALS['hook_shape_callbacks'][ $hook_name ][ $priority ] ) ) {
		return false;
	}

	foreach ( $GLOBALS['hook_shape_callbacks'][ $hook_name ][ $priority ] as $index => $registered ) {
		if ( $registered['callback'] === $callback ) {
			unset( $GLOBALS['hook_shape_callbacks'][ $hook_name ][ $priority ][ $index ] );
			return true;
		}
	}

	return false;
}

function apply_filters( $hook_name, $value, ...$args ) {
	$GLOBALS['hook_shape_dispatches'][] = $hook_name;
	$hook_args = array_merge( array( $value ), $args );
	$callbacks = $GLOBALS['hook_shape_callbacks'][ $hook_name ] ?? array();
	ksort( $callbacks, SORT_NUMERIC );

	foreach ( $callbacks as $priority_callbacks ) {
		foreach ( $priority_callbacks as $callback ) {
			$accepted_args = array_slice( $hook_args, 0, $callback['accepted_args'] );
			$hook_args[0] = $callback['callback']( ...$accepted_args );
		}
	}

	return $hook_args[0];
}

function do_action( $hook_name, ...$args ) {
	$GLOBALS['hook_shape_dispatches'][] = $hook_name;
	$callbacks = $GLOBALS['hook_shape_callbacks'][ $hook_name ] ?? array();
	ksort( $callbacks, SORT_NUMERIC );

	foreach ( $callbacks as $priority_callbacks ) {
		foreach ( $priority_callbacks as $callback ) {
			$accepted_args = array_slice( $args, 0, $callback['accepted_args'] );
			$callback['callback']( ...$accepted_args );
		}
	}
}
"""
            + product_runtime
            + """
include $argv[1];

echo 'HOOK_SHAPE_DISPATCHES=' . json_encode( $GLOBALS['hook_shape_dispatches'] ) . PHP_EOL;
""",
            encoding="utf-8",
        )

        result = subprocess.run(
            ["php", str(wrapper), str(DRIVER)],
            cwd=REPO,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

    assert result.returncode == 0, result.stderr
    output_lines = result.stdout.splitlines()
    capture = json.loads(next(line for line in output_lines if line.startswith("{")))
    dispatches = json.loads(
        next(line for line in output_lines if line.startswith("HOOK_SHAPE_DISPATCHES=")).split(
            "=", 1
        )[1]
    )
    return capture, dispatches


def write_snapshot(
    path: Path,
    *,
    role: str,
    hooks: dict[str, dict],
    runtime_owner: str | None = None,
    site_url: str | None = None,
    errors: list[str] | None = None,
) -> None:
    path.write_text(
        json.dumps(
            {
                "schema": "woopayments_hook_shape_capture.v1",
                "role": role,
                "runtime_owner": runtime_owner
                or ("native" if role == "target" else "plugin"),
                "site_url": site_url or f"http://{role}.localhost",
                "hooks": hooks,
                "preconditions": {"ready": True, "reasons": []},
                "errors": errors or [],
            },
            sort_keys=True,
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )


def base_hooks() -> dict[str, dict]:
    array_arg = {"type": "array", "keys": [], "values": {}}
    bool_arg = {"type": "bool"}
    int_arg = {"type": "int"}
    string_arg = {"type": "string"}
    product_arg = {
        "type": "object",
        "class": "WC_Product_Simple",
        "legacy_classes": ["WC_Product_Simple"],
        "methods": ["get_type"],
    }
    coupon_arg = {
        "type": "object",
        "class": "WC_Coupon",
        "legacy_classes": ["WC_Coupon"],
        "methods": [],
    }
    request_arg = {
        "type": "object",
        "class": "WCPay\\Core\\Server\\Request\\List_Transactions",
        "legacy_classes": ["WCPay\\Core\\Server\\Request\\List_Transactions"],
        "methods": ["get_params", "send"],
    }
    payment_type_arg = {
        "type": "object",
        "class": "WCPay\\Constants\\Payment_Type",
        "legacy_classes": ["WCPay\\Constants\\Payment_Type"],
        "methods": ["__toString", "equals"],
    }
    metadata_arg = {
        "type": "array",
        "keys": ["customer_email", "customer_name", "order_id", "payment_type"],
        "values": {
            "payment_type": payment_type_arg,
        },
    }
    order_arg = {"type": "object", "class": "WC_Order", "legacy_classes": ["WC_Order"], "methods": []}
    config_arg = {
        "type": "array",
        "keys": ["accountId", "gatewayId", "paymentMethodsConfig", "publishableKey"],
        "values": {},
    }
    webhook_args = [
        {"type": "string"},
        {"type": "array", "keys": ["data", "id", "type"], "values": {}},
    ]
    currency_format_arg = {
        "type": "array",
        "keys": ["currency_pos", "decimal_sep", "num_decimals", "thousand_sep"],
        "values": {},
    }
    payment_requires_action_args = [
        order_arg,
        string_arg,
        string_arg,
        string_arg,
        string_arg,
        string_arg,
    ]

    hooks: dict[str, dict] = {
        "wcpay_api_request_headers": {"args": [array_arg]},
        "wcpay_api_request_params": {"args": [array_arg, string_arg, string_arg]},
        "wcpay_api_request_response": {"args": [array_arg, string_arg, string_arg, string_arg]},
        "wcpay_list_fraud_outcome_transactions_request": {"args": [request_arg]},
        "wcpay_list_fraud_outcome_transactions_summary_request": {"args": [request_arg]},
        "wcpay_get_fraud_outcome_transactions_search_autocomplete_request": {"args": [request_arg]},
        "wcpay_get_fraud_outcome_transactions_export_request": {"args": [request_arg]},
        "wcpay_get_pm_promotions_request": {"args": [request_arg]},
        "wcpay_activate_pm_promotion_request": {"args": [request_arg]},
        "wcpay_get_authorization_request": {"args": [request_arg]},
        "wcpay_list_documents_request": {"args": [request_arg]},
        "wcpay_validate_vat_request": {"args": [request_arg]},
        "wcpay_get_active_loan_summary_request": {"args": [request_arg]},
        "wcpay_get_loans_request": {"args": [request_arg]},
        "wcpay_get_account_capital_link": {"args": [request_arg]},
        "wc_pay_get_authorizations_summary": {"args": [request_arg]},
        "wcpay_get_dispute_status_counts": {"args": [request_arg]},
        "wcpay_list_transactions_request": {"args": [request_arg]},
        "wcpay_list_disputes_request": {
            "args": [
                {
                    **request_arg,
                    "class": "WCPay\\Core\\Server\\Request\\List_Disputes",
                    "legacy_classes": ["WCPay\\Core\\Server\\Request\\List_Disputes"],
                }
            ]
        },
        "wcpay_list_deposits_request": {
            "args": [
                {
                    **request_arg,
                    "class": "WCPay\\Core\\Server\\Request\\List_Deposits",
                    "legacy_classes": ["WCPay\\Core\\Server\\Request\\List_Deposits"],
                }
            ]
        },
        "wcpay_list_authorizations_request": {
            "args": [
                {
                    **request_arg,
                    "class": "WCPay\\Core\\Server\\Request\\List_Authorizations",
                    "legacy_classes": ["WCPay\\Core\\Server\\Request\\List_Authorizations"],
                }
            ]
        },
        "wcpay_metadata_from_order": {"args": [metadata_arg, order_arg, payment_type_arg]},
        "wcpay_payment_fields_js_config": {"args": [config_arg]},
        "wc_payments_thank_you_page_bnpl_payment_method_logo_url": {"args": [string_arg, string_arg]},
        "wc_payments_thank_you_page_lpm_payment_method_logo_url": {"args": [string_arg, string_arg]},
        "wc_payments_account_id_for_intent_confirmation": {"args": [string_arg]},
        "wcpay_is_woopay_store_api_request": {"args": [bool_arg]},
        "wcpay_payment_request_is_product_supported": {"args": [bool_arg, product_arg]},
        "wcpay_payment_request_product_data": {"args": [array_arg, product_arg]},
        "wcpay_payment_request_supported_types": {"args": [array_arg]},
        "wcpay_payment_request_total_label": {"args": [string_arg]},
        "wcpay_test_mode": {"args": [bool_arg]},
        "wcpay_dev_mode": {"args": [bool_arg]},
        "wcpay_test_mode_onboarding": {"args": [bool_arg]},
        "wcpay_database_cache_ttl": {"args": [int_arg, string_arg, array_arg]},
        "wcpay_get_add_payment_method_redirect_url": {"args": [string_arg]},
        "wcpay_terminal_payment_completed_order_status": {"args": [string_arg]},
        "wcpay_create_customer_disallowed_order_statuses": {"args": [array_arg]},
        "wcpay_shopper_tracking_enabled": {"args": [bool_arg]},
        "wcpay_tracks_event_properties": {"args": [array_arg, string_arg]},
        "wcpay_woopay_is_signed_with_blog_token": {"args": [bool_arg]},
        "wcpay_webhook_platform_checkout_order_status_changed": {"args": [int_arg, string_arg]},
        "wc_payments_get_onboarding_data_args": {"args": [array_arg]},
        "woocommerce_payments_account_refreshed": {"args": [array_arg]},
        "woocommerce_payments_before_webhook_delivery": {"args": webhook_args},
        "woocommerce_payments_after_webhook_delivery": {"args": webhook_args},
        "woocommerce_woocommerce_payments_payment_requires_action": {"args": payment_requires_action_args},
        "wcpay_multi_currency_storefront_widget_css": {"args": [string_arg]},
        "wcpay_multi_currency_storefront_widget_instance": {"args": [array_arg]},
        "wcpay_multi_currency_storefront_widget_args": {"args": [array_arg]},
        "wcpay_multi_currency_override_notice_country": {"args": [string_arg]},
        "wcpay_multi_currency_override_notice_currency_name": {"args": [string_arg]},
        "wcpay_multi_currency_apply_charm_only_to_products": {"args": [bool_arg]},
        "wcpay_multi_currency_override_selected_currency": {"args": [bool_arg]},
        "wcpay_multi_currency_should_return_store_currency": {"args": [bool_arg]},
        "wcpay_multi_currency_should_convert_product_price": {"args": [bool_arg, product_arg]},
        "wcpay_multi_currency_should_convert_coupon_amount": {"args": [bool_arg, coupon_arg]},
        "wcpay_multi_currency_should_disable_currency_switching": {"args": [bool_arg]},
        "wcpay_multi_currency_should_hide_widgets": {"args": [bool_arg]},
        "wcpay_multi_currency_async_price_type": {"args": [string_arg, string_arg, array_arg]},
        "wcpay_multi_currency_disable_filter_select_clauses": {"args": [bool_arg]},
        "wcpay_multi_currency_filter_select_clauses": {"args": [array_arg]},
        "wcpay_multi_currency_disable_filter_join_clauses": {"args": [bool_arg]},
        "wcpay_multi_currency_filter_join_clauses": {"args": [array_arg]},
        "wcpay_multi_currency_disable_filter_where_clauses": {"args": [bool_arg]},
        "wcpay_multi_currency_filter_where_clauses": {"args": [array_arg]},
        "wcpay_multi_currency_disable_filter_select_orders_clauses": {"args": [bool_arg]},
        "wcpay_multi_currency_filter_select_orders_clauses": {"args": [array_arg]},
        "wcpay_{currency}_format": {"args": [currency_format_arg, string_arg]},
    }

    for hook in hooks.values():
        hook["observed"] = True
        hook["capture"] = "surrounding_path"

    assert sorted(hooks) == sorted(REQUIRED_HOOKS)
    return hooks


def test_usage_requires_ref_target_or_snapshot_files() -> None:
    result = run_gate()

    assert result.returncode == 2
    assert "usage:" in result.stderr
    assert "--ref" in result.stderr
    assert "--ref-state" in result.stderr


def test_print_plan_rejects_unsafe_wp_runner() -> None:
    result = run_gate(
        "--ref",
        "pnpm wp",
        "--target",
        TARGET_WP,
        "--print-plan",
    )

    assert result.returncode == 2
    assert "unsafe --ref WP runner" in result.stderr


def test_print_plan_lists_required_hooks() -> None:
    result = run_gate("--ref", REF_WP, "--target", TARGET_WP, "--print-plan")

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["schema"] == "woopayments_hook_shape_gate_plan.v1"
    assert payload["ref_wp"] == REF_WP
    assert payload["target_wp"] == TARGET_WP
    assert payload["required_hooks"] == REQUIRED_HOOKS


def test_gate_passes_matching_snapshot_files() -> None:
    with tempfile.TemporaryDirectory(prefix="hook-shape-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref.json"
        target_state = tmp_path / "target.json"
        out_dir = tmp_path / "evidence"

        write_snapshot(ref_state, role="reference", hooks=base_hooks())
        write_snapshot(target_state, role="target", hooks=base_hooks())

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 0, result.stderr
        assert "PASS: preserved WooPayments hook argument shapes match." in result.stdout

        rollup = json.loads((out_dir / "hook-shape-parity.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["failures"] == []


def test_gate_blocks_capture_errors_without_reporting_target_gaps() -> None:
    with tempfile.TemporaryDirectory(prefix="hook-shape-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref.json"
        target_state = tmp_path / "target.json"
        out_dir = tmp_path / "evidence"
        target_hooks = base_hooks()
        del target_hooks["wcpay_payment_fields_js_config"]

        write_snapshot(ref_state, role="reference", hooks=base_hooks())
        write_snapshot(
            target_state,
            role="target",
            hooks=target_hooks,
            errors=["express product probe: invalid fixture"],
        )

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 3
        assert "BLOCKED: target capture error: express product probe: invalid fixture" in result.stderr
        assert "target missing required hook" not in result.stderr
        assert "FAIL: preserved WooPayments hook argument shape drift detected." not in result.stderr

        rollup = json.loads((out_dir / "hook-shape-parity.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert rollup["failures"] == []
        assert rollup["blocked"] == [
            "target capture error: express product probe: invalid fixture"
        ]


def test_gate_blocks_matching_shapes_with_wrong_runtime_owner() -> None:
    with tempfile.TemporaryDirectory(prefix="hook-shape-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref.json"
        target_state = tmp_path / "target.json"

        write_snapshot(ref_state, role="reference", hooks=base_hooks())
        write_snapshot(
            target_state,
            role="target",
            hooks=base_hooks(),
            runtime_owner="plugin",
        )

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--out-dir",
            str(tmp_path / "evidence"),
        )

        assert result.returncode == 3
        assert "target runtime owner must be native" in result.stderr


def test_gate_blocks_matching_shapes_from_the_same_site() -> None:
    with tempfile.TemporaryDirectory(prefix="hook-shape-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref.json"
        target_state = tmp_path / "target.json"
        shared_url = "http://same-store.localhost"

        write_snapshot(
            ref_state,
            role="reference",
            hooks=base_hooks(),
            site_url=shared_url,
        )
        write_snapshot(
            target_state,
            role="target",
            hooks=base_hooks(),
            site_url=shared_url,
        )

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--out-dir",
            str(tmp_path / "evidence"),
        )

        assert result.returncode == 3
        assert "distinct local sites" in result.stderr


def test_gate_supports_explicit_plugin_self_check_snapshots() -> None:
    with tempfile.TemporaryDirectory(prefix="hook-shape-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref.json"
        target_state = tmp_path / "target.json"
        shared_url = "http://reference.localhost"

        write_snapshot(
            ref_state,
            role="reference",
            hooks=base_hooks(),
            site_url=shared_url,
        )
        write_snapshot(
            target_state,
            role="target",
            hooks=base_hooks(),
            runtime_owner="plugin",
            site_url=shared_url,
        )

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--out-dir",
            str(tmp_path / "evidence"),
            "--self-check",
        )

        assert result.returncode == 0, result.stderr


def test_gate_rejects_mislabeled_snapshot_roles() -> None:
    with tempfile.TemporaryDirectory(prefix="hook-shape-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref.json"
        target_state = tmp_path / "target.json"

        write_snapshot(ref_state, role="eval-file", hooks=base_hooks())
        write_snapshot(target_state, role="target", hooks=base_hooks())

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--out-dir",
            str(tmp_path / "evidence"),
        )

        assert result.returncode == 1
        assert "reference snapshot role must be reference" in result.stderr


def test_gate_fails_on_argument_shape_drift() -> None:
    with tempfile.TemporaryDirectory(prefix="hook-shape-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref.json"
        target_state = tmp_path / "target.json"
        out_dir = tmp_path / "evidence"
        target_hooks = base_hooks()
        target_hooks["wcpay_metadata_from_order"]["args"][2] = {"type": "string"}

        write_snapshot(ref_state, role="reference", hooks=base_hooks())
        write_snapshot(target_state, role="target", hooks=target_hooks)

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 1
        assert "wcpay_metadata_from_order arg[2]" in result.stderr

        rollup = json.loads((out_dir / "hook-shape-parity.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert "wcpay_metadata_from_order arg[2]" in "\n".join(rollup["failures"])


def test_gate_fails_on_nested_argument_shape_drift() -> None:
    with tempfile.TemporaryDirectory(prefix="hook-shape-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref.json"
        target_state = tmp_path / "target.json"
        out_dir = tmp_path / "evidence"
        target_hooks = base_hooks()
        target_hooks["wcpay_metadata_from_order"]["args"][0]["values"]["payment_type"] = {
            "type": "string"
        }

        write_snapshot(ref_state, role="reference", hooks=base_hooks())
        write_snapshot(target_state, role="target", hooks=target_hooks)

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 1
        assert "wcpay_metadata_from_order arg[0].payment_type" in result.stderr


def test_gate_rejects_synthetic_fallback_for_required_hook() -> None:
    with tempfile.TemporaryDirectory(prefix="hook-shape-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref.json"
        target_state = tmp_path / "target.json"
        out_dir = tmp_path / "evidence"
        target_hooks = base_hooks()
        target_hooks["wcpay_payment_fields_js_config"]["observed"] = False
        target_hooks["wcpay_payment_fields_js_config"]["capture"] = "synthetic_fallback"

        write_snapshot(ref_state, role="reference", hooks=base_hooks())
        write_snapshot(target_state, role="target", hooks=target_hooks)

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 1
        assert "target required hook was not observed: wcpay_payment_fields_js_config" in result.stderr


def test_gate_rejects_direct_boundary_probe_marked_as_observed() -> None:
    with tempfile.TemporaryDirectory(prefix="hook-shape-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref.json"
        target_state = tmp_path / "target.json"
        out_dir = tmp_path / "evidence"
        target_hooks = base_hooks()
        target_hooks["wcpay_payment_fields_js_config"]["capture"] = "direct_boundary_probe"

        write_snapshot(ref_state, role="reference", hooks=base_hooks())
        write_snapshot(target_state, role="target", hooks=target_hooks)

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 1
        assert (
            "target required hook did not use its surrounding product path: "
            "wcpay_payment_fields_js_config"
        ) in result.stderr


def test_gate_fails_when_required_hook_is_missing() -> None:
    with tempfile.TemporaryDirectory(prefix="hook-shape-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref.json"
        target_state = tmp_path / "target.json"
        out_dir = tmp_path / "evidence"
        target_hooks = base_hooks()
        del target_hooks["wcpay_payment_fields_js_config"]

        write_snapshot(ref_state, role="reference", hooks=base_hooks())
        write_snapshot(target_state, role="target", hooks=target_hooks)

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 1
        assert "target missing required hook: wcpay_payment_fields_js_config" in result.stderr


def test_php_driver_exports_inventory_without_wordpress() -> None:
    result = subprocess.run(
        ["php", str(DRIVER), "--inventory"],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["schema"] == "woopayments_hook_shape_inventory.v1"
    assert payload["required_hooks"] == REQUIRED_HOOKS
    assert "wcpay_metadata_from_order" in payload["preserved_hooks"]


def test_php_driver_requires_every_preserved_hook() -> None:
    result = subprocess.run(
        ["php", str(DRIVER), "--inventory"],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["required_hooks"] == payload["preserved_hooks"]


def test_metadata_probe_calls_the_current_request_builder_owner() -> None:
    source = DRIVER.read_text(encoding="utf-8")

    assert "WooPaymentsIntentRequestBuilder::metadata_from_order" in source
    assert "WooPaymentsIntentCodec::metadata_from_order" not in source


def test_php_driver_inventory_is_derived_from_probe_manifest() -> None:
    result = subprocess.run(
        ["php", str(DRIVER), "--inventory"],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)
    manifest = payload["probe_manifest"]

    assert list(manifest) == REQUIRED_HOOKS
    for hook_name, probe in manifest.items():
        assert probe["group"], hook_name
        assert probe["plugin_callable"], hook_name
        assert probe["native_callable"], hook_name


def test_fraud_probe_uses_valid_block_status_for_both_runtimes() -> None:
    source = DRIVER.read_text(encoding="utf-8")

    assert "$request->set_status( 'block' );" in source
    assert "$request->set_param( 'status', 'block' );" in source
    assert "$request->set_param( 'status', 'blocked' );" not in source


def test_pinned_rest_controller_probe_injects_api_client() -> None:
    source = DRIVER.read_text(encoding="utf-8")

    assert (
        "$controller = new $controller_class( WC_Payments::get_payments_api_client() );"
        in source
    )


def test_extension_express_product_probe_supplies_product_object() -> None:
    source = DRIVER.read_text(encoding="utf-8")
    section = source[
        source.index("$express_product_hooks = array(") : source.index(
            "foreach ( array( 'wc_payments_thank_you_page_bnpl_payment_method_logo_url'"
        )
    ]

    assert "public function get_product()" in section
    assert "return $GLOBALS['product'] ?? null;" in section


def test_extension_mode_probe_uses_fresh_mode_per_hook() -> None:
    source = DRIVER.read_text(encoding="utf-8")
    section = source[
        source.index("$account_mode_methods = array(") : source.index(
            "$express_product_hooks = array("
        )
    ]

    assert "new WCPay\\Core\\Mode()" in section
    assert "WC_Payments::mode()" not in section


def test_extension_woopay_probe_uses_namespaced_session_class() -> None:
    source = DRIVER.read_text(encoding="utf-8")
    section = source[
        source.index("'WooPay signed-request surrounding path'") : source.index(
            "$run_until_hook(\n\t'wcpay_is_woopay_store_api_request'"
        )
    ]

    assert r"'WCPay\WooPay\WooPay_Session'" in section
    assert "class_exists( 'WooPay_Session' )" not in section


def test_requires_action_probe_saves_order_before_payment_information() -> None:
    source = DRIVER.read_text(encoding="utf-8")
    section = source[
        source.index("$payment_requires_action_probe = static function") : source.index(
            "if ( 'native' === $runtime_owner )"
        )
    ]

    assert "$order->save();" in section
    assert section.index("$order->save();") < section.index(
        "$payment_information = new WCPay\\Payment_Information("
    )


def test_native_add_method_fake_overrides_active_token_method() -> None:
    source = DRIVER.read_text(encoding="utf-8")
    section = source[
        source.index("$run_until_hook(\n\t'wcpay_get_add_payment_method_redirect_url'") : source.index(
            "$payment_requires_action_probe = static function"
        )
    ]

    assert "public function get_or_create_token_for_user(" in section
    assert "public function get_or_create_card_token_for_user(" not in section


def test_extension_vat_probe_uses_the_real_controller_even_when_its_route_is_feature_gated() -> None:
    source = DRIVER.read_text(encoding="utf-8")
    section = source[
        source.index("if ( 'wcpay_validate_vat_request' === $hook_name") : source.index(
            "$plugin_controller_methods = array("
        )
    ]

    assert "class-wc-rest-payments-vat-controller.php" in section
    assert "new WC_REST_Payments_VAT_Controller( WC_Payments::get_payments_api_client() )" in section
    assert "$controller->validate_vat( $request );" in section
    assert "rest_do_request" not in section


def test_php_driver_ignores_wp_cli_command_name_when_parsing_capture_role() -> None:
    capture, _dispatches = run_driver_with_offline_hook_runtime(
        runtime_args=["eval-file", "target"]
    )

    assert capture["role"] == "target"


def test_php_driver_direct_probes_are_diagnostic_not_product_observations() -> None:
    capture, dispatches = run_driver_with_offline_hook_runtime()

    direct_probe_hooks = [
        hook
        for hook in REQUIRED_HOOKS
        if capture["hooks"].get(hook, {}).get("observed") is False
        and capture["hooks"].get(hook, {}).get("capture") == "direct_boundary_probe"
    ]
    expected_runtime_hooks = {
        hook.replace("{currency}", "usd") for hook in REQUIRED_HOOKS
    }

    assert capture["errors"] == []
    assert sorted(direct_probe_hooks) == sorted(REQUIRED_HOOKS)
    assert expected_runtime_hooks <= set(dispatches)
    assert capture["hooks"]["wcpay_{currency}_format"]["runtime_hook"] == "wcpay_usd_format"


def test_nested_hook_during_direct_diagnostic_cannot_gain_product_provenance() -> None:
    capture, _ = run_driver_with_offline_hook_runtime(
        r"""
add_filter(
	'wcpay_test_mode',
	static function ( $value ) {
		apply_filters( 'wcpay_dev_mode', false );
		return $value;
	},
	PHP_INT_MIN + 2,
	1
);
"""
    )

    assert capture["hooks"]["wcpay_test_mode"]["capture"] == "direct_boundary_probe"
    assert capture["hooks"]["wcpay_dev_mode"]["capture"] == "direct_boundary_probe"
    assert capture["hooks"]["wcpay_dev_mode"]["observed"] is False


def test_priority_extension_paths_are_captured_as_surrounding_product_paths() -> None:
    capture, _ = run_driver_with_offline_hook_runtime(EXTENSION_PRODUCT_RUNTIME)

    assert capture["errors"] == []
    for hook_name in PRIORITY_SURROUNDING_PATH_HOOKS:
        assert capture["hooks"][hook_name]["observed"] is True, hook_name
        assert capture["hooks"][hook_name]["capture"] == "surrounding_path", hook_name


def test_priority_native_paths_are_captured_as_surrounding_product_paths() -> None:
    capture, _ = run_driver_with_offline_hook_runtime(NATIVE_PRODUCT_RUNTIME)

    assert capture["errors"] == []
    for hook_name in PRIORITY_SURROUNDING_PATH_HOOKS:
        assert capture["hooks"][hook_name]["observed"] is True, hook_name
        assert capture["hooks"][hook_name]["capture"] == "surrounding_path", hook_name


def test_verify_runs_hook_shape_gate() -> None:
    verify_source = VERIFY.read_text(encoding="utf-8")

    assert "hook-shape-parity.sh" in verify_source
