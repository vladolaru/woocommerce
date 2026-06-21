#!/usr/bin/env python3
"""Focused regression checks for A4ar harness hardening."""

from __future__ import annotations

import importlib.util
import json
import subprocess
import tempfile
from pathlib import Path
from types import SimpleNamespace


REPO = Path(__file__).resolve().parents[2]
MODULE_PATH = REPO / "tools/woopayments-merge/a4aq-accumulated-gate.py"


def load_module():
    spec = importlib.util.spec_from_file_location("a4aq_accumulated_gate", MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)
    return module


def make_gate(module):
    args = SimpleNamespace(
        repo=str(REPO),
        plugin_repo="/Users/vladolaru/Work/a8c/woocommerce-payments",
        ref_wp="docker exec -i wcpay_wp_default wp --allow-root --user=1",
        target_wp="docker exec -i example-cli-1 wp --allow-root --user=1",
        playwriter_session="unit",
        out_dir=tempfile.mkdtemp(prefix="a4ar-harness-test-"),
        skip_admin_browser=False,
        skip_optional_admin_scenario=False,
        skip_checkout_browser=True,
        skip_perf=True,
        skip_perf_fixtures=True,
        skip_bundle=True,
    )
    return module.Gate(args)


def assert_raises(fn, expected: str):
    try:
        fn()
    except Exception as exc:
        assert expected in str(exc), f"Expected {expected!r} in {exc!r}"
    else:
        raise AssertionError(f"Expected exception containing {expected!r}")


def test_optional_admin_unavailable_set_is_strict(module):
    gate = make_gate(module)
    result = {"id": "admin-browser", "status": "pass", "failures": [], "incomplete_reasons": []}
    evidence_path = Path(gate.out_dir) / "admin.json"
    evidence_path.write_text(
        """
{
  "stores": [ "target" ],
  "results": [
    { "store": "target", "surface": "admin-navigation", "viewport": "desktop", "passed": true },
    { "store": "target", "surface": "documents", "viewport": "desktop", "passed": true, "targetSurfaceAvailable": false, "coverageStatus": "unavailable-guard-pass" },
    { "store": "target", "surface": "future-route", "viewport": "desktop", "passed": true, "targetSurfaceAvailable": false, "coverageStatus": "unavailable-guard-pass" }
  ]
}
""".strip(),
        encoding="utf-8",
    )

    gate.validate_browser_evidence("admin-browser", evidence_path, result)

    assert result["status"] == "fail"
    assert any("unexpected unavailable target admin routes" in failure for failure in result["failures"])


def test_optional_admin_available_evidence_is_required(module):
    gate = make_gate(module)
    gate.required_optional_admin_checks = {("documents", "desktop"), ("documents", "mobile")}
    result = {"id": "admin-browser-optional-account", "status": "pass", "failures": [], "incomplete_reasons": []}
    evidence_path = Path(gate.out_dir) / "optional.json"
    evidence_path.write_text(
        """
{
  "stores": [ "target" ],
  "results": [
    { "store": "target", "surface": "admin-navigation", "viewport": "desktop", "passed": true },
    { "store": "target", "surface": "documents", "viewport": "desktop", "passed": true, "targetSurfaceAvailable": true, "coverageStatus": "available-route-pass" }
  ]
}
""".strip(),
        encoding="utf-8",
    )

    gate.validate_browser_evidence("admin-browser-optional-account", evidence_path, result)
    gate.validate_optional_admin_coverage(evidence_path, result)

    assert result["status"] == "fail"
    assert any("missing available-route proof" in failure for failure in result["failures"])


def test_restore_flags_must_match_apply_before(module):
    gate = make_gate(module)
    apply_payload = {
        "before": {
            "card_present_eligible": True,
            "has_card_readers_available": False,
            "has_previous_capital_loans": False,
            "is_documents_enabled": False,
        }
    }
    restore_payload = {
        "restored_snapshot_exact": True,
        "after": {
            "card_present_eligible": True,
            "has_card_readers_available": True,
            "has_previous_capital_loans": False,
            "is_documents_enabled": False,
        }
    }

    assert_raises(
        lambda: gate.validate_optional_admin_restore("target", apply_payload, restore_payload),
        "restore flags do not match",
    )


def test_perf_refund_fixture_requires_provider_ids(module):
    gate = make_gate(module)
    order = {
        "order_id": 10,
        "exists": True,
        "gateway_id": "woocommerce_payments",
        "payment_method": "woocommerce_payments",
        "remaining_refund_amount": "10.00",
        "transaction_id": "ch_fake",
        "total": "10.00",
        "refunded_amount": "0",
        "intent_id": "",
        "charge_id": "",
        "status": "processing",
    }

    assert_raises(
        lambda: gate.validate_perf_order_fixture("target", "refund", order),
        "missing intent id",
    )


def test_perf_capture_fixture_requires_charge_id(module):
    gate = make_gate(module)
    order = {
        "order_id": 11,
        "exists": True,
        "gateway_id": "woocommerce_payments",
        "payment_method": "woocommerce_payments",
        "remaining_refund_amount": "10.00",
        "transaction_id": "ch_fake",
        "total": "10.00",
        "refunded_amount": "0",
        "intention_status": "requires_capture",
        "intent_id": "pi_fake",
        "charge_id": "",
    }

    assert_raises(
        lambda: gate.validate_perf_order_fixture("target", "capture", order),
        "missing charge id",
    )


def test_reference_severe_logs_fail(module):
    gate = make_gate(module)
    target = {"diagnostics": [], "server_errors": [], "debug_diagnostics": []}
    reference = {
        "diagnostics": ["PHP Fatal error: boom"],
        "server_errors": [],
        "debug_diagnostics": [],
    }

    assert gate.evaluate_log_status(target, reference, []) == "fail"


def test_wp_eval_file_timeout_is_reported(module):
    gate = make_gate(module)
    original_run = module.subprocess.run

    def fake_run(*args, **kwargs):
        raise subprocess.TimeoutExpired(cmd=args[0], timeout=kwargs.get("timeout"), output="partial stdout", stderr="partial stderr")

    module.subprocess.run = fake_run
    try:
        result = gate.run_wp_eval_file(
            "target-wp",
            "docker exec -i example-cli-1 wp --allow-root --user=1",
            Path(__file__),
            [],
            timeout_seconds=0.001,
        )
    finally:
        module.subprocess.run = original_run

    assert result["exit_code"] == module.EXIT_TIMEOUT
    assert "timed out" in result["stderr_tail"]


def test_account_restore_preserves_autoload_state(module):
    script = REPO / "tools/woopayments-merge/a4-account-scenario.php"
    initial_cache = {
        "data": {
            "account_id": "acct_test",
            "status": "restricted_soon",
            "card_present_eligible": True,
            "has_card_readers_available": False,
            "is_documents_enabled": False,
            "capital": {"has_previous_loans": False},
        },
        "fetched": 123,
        "errored": False,
    }
    php_wrapper = r"""<?php
$store_file = $argv[1];
$script = $argv[2];
$args = array_slice( $argv, 3 );

function a4_test_read_store() {
	$store_file = $GLOBALS['store_file'];
	return json_decode( file_get_contents( $store_file ), true );
}

function a4_test_write_store( $store ) {
	$store_file = $GLOBALS['store_file'];
	file_put_contents( $store_file, json_encode( $store ) );
}

function get_option( $option, $default = false ) {
	$store = a4_test_read_store();
	if ( ! isset( $store[ $option ] ) || empty( $store[ $option ]['exists'] ) ) {
		return $default;
	}
	return $store[ $option ]['value'];
}

function update_option( $option, $value, $autoload = null ) {
	$store = a4_test_read_store();
	if ( ! isset( $store[ $option ] ) ) {
		$store[ $option ] = array( 'exists' => true, 'autoload' => null, 'value' => null );
	}
	$store[ $option ]['exists'] = true;
	$store[ $option ]['value'] = $value;
	if ( null !== $autoload ) {
		$store[ $option ]['autoload'] = $autoload;
	}
	a4_test_write_store( $store );
	return true;
}

function delete_option( $option ) {
	$store = a4_test_read_store();
	unset( $store[ $option ] );
	a4_test_write_store( $store );
	return true;
}

function wp_cache_delete( $key, $group = '' ) {
	return true;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

class A4_Test_DB {
	public $options = 'wp_options';

	public function prepare( $query, $option ) {
		return $option;
	}

	public function get_var( $option ) {
		$store = a4_test_read_store();
		return $store[ $option ]['autoload'] ?? null;
	}

	public function update( $table, $data, $where ) {
		$store = a4_test_read_store();
		$option = $where['option_name'];
		if ( isset( $store[ $option ] ) && array_key_exists( 'autoload', $data ) ) {
			$store[ $option ]['autoload'] = $data['autoload'];
		}
		a4_test_write_store( $store );
		return true;
	}
}

$wpdb = new A4_Test_DB();

class WP_CLI {
	public static function line( $line ) {
		echo $line . PHP_EOL;
	}

	public static function error( $message ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
}

include $script;
"""

    with tempfile.TemporaryDirectory(prefix="a4ar-account-autoload-") as tmp:
        tmp_path = Path(tmp)
        wrapper_path = tmp_path / "wp-cli-wrapper.php"
        store_path = tmp_path / "store.json"
        wrapper_path.write_text(php_wrapper, encoding="utf-8")
        store_path.write_text(
            json.dumps(
                {
                    "wcpay_account_data": {
                        "exists": True,
                        "autoload": "yes",
                        "value": initial_cache,
                    }
                }
            ),
            encoding="utf-8",
        )

        def run_scenario(*args: str) -> subprocess.CompletedProcess:
            return subprocess.run(
                ["php", str(wrapper_path), str(store_path), str(script), *args],
                check=True,
                capture_output=True,
                text=True,
            )

        snapshot = json.loads(run_scenario("snapshot").stdout)["snapshot"]
        run_scenario("apply-optional-admin")
        run_scenario("restore", snapshot)
        restored_store = json.loads(store_path.read_text(encoding="utf-8"))

    assert restored_store["wcpay_account_data"]["autoload"] == "yes"


def main() -> None:
    module = load_module()
    tests = [
        test_optional_admin_unavailable_set_is_strict,
        test_optional_admin_available_evidence_is_required,
        test_restore_flags_must_match_apply_before,
        test_perf_refund_fixture_requires_provider_ids,
        test_perf_capture_fixture_requires_charge_id,
        test_reference_severe_logs_fail,
        test_wp_eval_file_timeout_is_reported,
        test_account_restore_preserves_autoload_state,
    ]
    for test in tests:
        test(module)
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
