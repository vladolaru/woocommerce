#!/usr/bin/env python3
"""Behavior checks for verifier-owned WooPayments order cleanup."""

from __future__ import annotations

import base64
import json
import os
import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
CLEANUP = REPO / "tools/woopayments-merge/verify-owned-order-cleanup.php"
RESULT_PREFIX = "WCPAY_VERIFY_OWNED_ORDER_CLEANUP:"
RUN_TOKEN = "wcpay-verify-0123456789abcdef0123456789abcdef"


def run_cleanup(
    marker: str,
    *,
    created_via: str = "",
    native_run_token: str = "",
    explicit_ids: tuple[str, ...] = ("1575",),
    discover: bool = False,
    order_exists: bool = True,
    delete_succeeds: bool = True,
) -> tuple[subprocess.CompletedProcess[str], dict]:
    assert CLEANUP.exists(), "the verifier cleanup helper is missing"
    encoded_marker = base64.b64encode(marker.encode()).decode()
    php_args = ", ".join(f"'{value}'" for value in (RUN_TOKEN, *explicit_ids))

    with tempfile.TemporaryDirectory(prefix="verify-order-cleanup-") as tmp_name:
        wrapper = Path(tmp_name) / "run.php"
        wrapper.write_text(
            """<?php
$args = array( __PHP_ARGS__ );
$deleted = false;
$order_exists = '1' === getenv( 'ORDER_EXISTS' );

class FakeCleanupOrder {
    public function get_meta( $key, $single ) {
        if ( '_wcpay_verify_run_token' === $key ) {
            return getenv( 'NATIVE_RUN_TOKEN' );
        }
        return base64_decode( getenv( 'TEST_LAB_MARKER_B64' ), true );
    }

    public function get_created_via() {
        return getenv( 'CREATED_VIA' );
    }

    public function delete( $force ) {
        if ( '1' === getenv( 'DELETE_SUCCEEDS' ) ) {
            $GLOBALS['deleted'] = true;
            return true;
        }
        return false;
    }
}

$fake_order = new FakeCleanupOrder();

function absint( $value ) {
    return abs( (int) $value );
}

function wc_get_order( $order_id ) {
    if ( 1575 !== $order_id || ! $GLOBALS['order_exists'] || $GLOBALS['deleted'] ) {
        return false;
    }
    return $GLOBALS['fake_order'];
}

function wc_get_orders( $query ) {
    return '1' === getenv( 'DISCOVER' ) ? array( 1575 ) : array();
}

function wc_get_order_statuses() {
    return array( 'wc-pending' => 'Pending' );
}

function wp_json_encode( $value ) {
    return json_encode( $value );
}

class WP_CLI {
    public static function halt( $status ) {
        exit( $status );
    }
}

require $argv[1];
""".replace("__PHP_ARGS__", php_args),
            encoding="utf-8",
        )

        result = subprocess.run(
            ["php", str(wrapper), str(CLEANUP)],
            cwd=REPO,
            env={
                **os.environ,
                "TEST_LAB_MARKER_B64": encoded_marker,
                "CREATED_VIA": created_via,
                "NATIVE_RUN_TOKEN": native_run_token,
                "DISCOVER": "1" if discover else "0",
                "ORDER_EXISTS": "1" if order_exists else "0",
                "DELETE_SUCCEEDS": "1" if delete_succeeds else "0",
            },
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

    line = next(
        line for line in result.stdout.splitlines() if line.startswith(RESULT_PREFIX)
    )
    return result, json.loads(line.removeprefix(RESULT_PREFIX))


def test_json_encoded_test_lab_provenance_is_owned() -> None:
    marker = json.dumps(
        {
            "created_by": "test-lab",
            "operation": "charges",
            "protocol": RUN_TOKEN,
        }
    )

    result, payload = run_cleanup(marker)

    assert result.returncode == 0, result.stdout + result.stderr
    assert payload == {
        "success": True,
        "results": [{"order_id": 1575, "status": "deleted"}],
    }


def test_unowned_order_is_refused_without_deletion() -> None:
    result, payload = run_cleanup(json.dumps({"created_by": "another-tool"}))

    assert result.returncode == 1
    assert payload == {
        "success": False,
        "results": [{"order_id": 1575, "status": "ownership_mismatch"}],
    }


def test_native_deterministic_order_provenance_is_owned() -> None:
    result, payload = run_cleanup(
        "",
        created_via="harness-native-charge",
        native_run_token=RUN_TOKEN,
    )

    assert result.returncode == 0, result.stdout + result.stderr
    assert payload == {
        "success": True,
        "results": [{"order_id": 1575, "status": "deleted"}],
    }


def test_run_token_discovers_order_when_driver_emits_no_id() -> None:
    marker = json.dumps(
        {
            "created_by": "test-lab",
            "operation": "charges",
            "protocol": RUN_TOKEN,
        }
    )

    result, payload = run_cleanup(marker, explicit_ids=(), discover=True)

    assert result.returncode == 0, result.stdout + result.stderr
    assert payload["results"] == [{"order_id": 1575, "status": "deleted"}]


def test_already_absent_explicit_order_is_idempotent() -> None:
    result, payload = run_cleanup("", order_exists=False)

    assert result.returncode == 0, result.stdout + result.stderr
    assert payload["results"] == [{"order_id": 1575, "status": "already_absent"}]


def test_delete_failure_is_reported() -> None:
    marker = json.dumps(
        {
            "created_by": "test-lab",
            "operation": "charges",
            "protocol": RUN_TOKEN,
        }
    )

    result, payload = run_cleanup(marker, delete_succeeds=False)

    assert result.returncode == 1
    assert payload == {
        "success": False,
        "results": [{"order_id": 1575, "status": "delete_failed"}],
    }


def main() -> None:
    tests = [
        test_json_encoded_test_lab_provenance_is_owned,
        test_unowned_order_is_refused_without_deletion,
        test_native_deterministic_order_provenance_is_owned,
        test_run_token_discovers_order_when_driver_emits_no_id,
        test_already_absent_explicit_order_is_idempotent,
        test_delete_failure_is_reported,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
