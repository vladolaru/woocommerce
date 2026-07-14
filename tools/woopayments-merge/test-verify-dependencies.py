#!/usr/bin/env python3
"""Regression checks for merge-harness dependencies and source guards."""

from __future__ import annotations

import subprocess
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
FINANCIAL_RECONCILE = REPO / "tools/woopayments-merge/financial-reconcile.sh"
NATIVE_CHARGE_DRIVER = REPO / "tools/woopayments-merge/flow-drive-native-charge.php"

VERIFY_DEPENDENCIES = (
    "tools/woopayments-merge/bc-drift-gate.sh",
    "tools/woopayments-merge/bc-drift-baseline/endpoints.txt",
    "tools/woopayments-merge/bc-drift-baseline/hooks_filters.txt",
    "tools/woopayments-merge/bc-drift-baseline/persisted_data.txt",
    "tools/woopayments-merge/bc-drift-baseline/php_api.txt",
    "tools/woopayments-merge/bc-drift-baseline/scheduler.txt",
    "tools/woopayments-merge/bc-drift-baseline/source-provenance.txt",
    "tools/woopayments-merge/bc-drift-baseline/tracks.txt",
    "tools/woopayments-merge/parity-diff.sh",
    "tools/woopayments-merge/dump-bucket-e-surface.sh",
    "tools/woopayments-merge/dump-bucket-e-surface.php",
    "tools/woopayments-merge/normalize-bucket-e-cross.py",
    "tools/woopayments-merge/perf-baseline.sh",
    "tools/woopayments-merge/perf-baseline.php",
    "tools/woopayments-merge/perf-baseline.json",
    "tools/woopayments-merge/financial-reconcile.sh",
    "tools/woopayments-merge/local-runner-safety.sh",
    "tools/woopayments-merge/local_runner_safety.py",
    "tools/woopayments-merge/lpm_evidence.py",
    "tools/woopayments-merge/manual-evidence-classifier.py",
    "tools/woopayments-merge/tracks-parity.sh",
    "tools/woopayments-merge/tracks-normalize.py",
    "tools/woopayments-merge/tracks-continuity-inventory.tsv",
    "tools/woopayments-merge/native-hook-naming-gate.sh",
    "tools/woopayments-merge/test-native-hook-naming-gate.py",
    "tools/woopayments-merge/plugin-active-settings-gate.sh",
    "tools/woopayments-merge/plugin-active-settings.playwriter.mjs",
    "tools/woopayments-merge/run-command-with-timeout.py",
    "tools/woopayments-merge/verify-owned-order-cleanup.php",
)

FINAL_EVIDENCE_DEPENDENCIES = VERIFY_DEPENDENCIES + (
    "tools/woopayments-merge/run-self-tests.sh",
    "tools/pytest.ini",
    "tools/woopayments-critical-flows/build-agent-results.py",
    "tools/woopayments-critical-flows/evidence_context.py",
    "tools/woopayments-merge/a4-account-scenario.php",
    "tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs",
    "tools/woopayments-merge/a4-admin-surface-gate.py",
    "tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs",
    "tools/woopayments-merge/a4-checkout-fixture-state.php",
    "tools/woopayments-merge/a4-perf-fixture-inspect.php",
    "tools/woopayments-merge/a4aq-accumulated-gate.py",
    "tools/woopayments-merge/flow-drive.sh",
    "tools/woopayments-merge/flow-drive-capture.php",
    "tools/woopayments-merge/flow-drive-deterministic-charge.php",
    "tools/woopayments-merge/flow-drive-native-charge.php",
    "tools/woopayments-merge/flow-drive-refund.php",
    "tools/woopayments-merge/flow-drive-unpaid-order.php",
    "tools/woopayments-merge/money-path-parity-gate.sh",
    "tools/woopayments-merge/money-path-parity-preflight.php",
    "tools/woopayments-merge/multi-currency-runtime-state.sh",
    "tools/woopayments-merge/perf-fixtures-gate.py",
    "tools/woopayments-merge/perf_fixtures.py",
    "tools/woopayments-merge/playwright-script-runner.mjs",
    "tools/woopayments-merge/sc04-saved-card-gate.py",
    "tools/woopayments-merge/sc04-saved-card.playwriter.mjs",
)

FINAL_EVIDENCE_TEST_DEPENDENCIES = (
    "tools/woopayments-critical-flows/test-agent-results.py",
    "tools/woopayments-critical-flows/test-evidence-context.py",
    "tools/woopayments-merge/test-a4ar-harness-regressions.py",
    "tools/woopayments-merge/test-a4au-admin-surface-regressions.py",
    "tools/woopayments-merge/test-money-path-parity-gate.py",
    "tools/woopayments-merge/test-converted-currency-gate.py",
    "tools/woopayments-merge/test-flow-drive.py",
    "tools/woopayments-merge/test-local-runner-safety.py",
    "tools/woopayments-merge/test-sc04-saved-card-gate.py",
    "tools/woopayments-merge/test-verify-owned-order-cleanup.py",
    "tools/woopayments-merge/tests/a4-admin-surface-fixtures.sh",
)


def is_tracked(path: str) -> bool:
    return (
        subprocess.run(
            ["git", "ls-files", "--error-unmatch", path],
            cwd=REPO,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=False,
        ).returncode
        == 0
    )


def test_verify_base_dependencies_are_tracked() -> None:
    missing = [path for path in VERIFY_DEPENDENCIES if not is_tracked(path)]

    assert missing == []


def test_final_evidence_runtime_dependencies_are_tracked() -> None:
    missing = [path for path in FINAL_EVIDENCE_DEPENDENCIES if not is_tracked(path)]

    assert missing == []


def test_final_evidence_regression_dependencies_are_tracked() -> None:
    missing = [path for path in FINAL_EVIDENCE_TEST_DEPENDENCIES if not is_tracked(path)]

    assert missing == []


def test_financial_reconcile_dispute_id_extractor_requires_provider_id_shape() -> None:
    source = FINANCIAL_RECONCILE.read_text(encoding="utf-8")

    assert "$find_provider_id_meta_like" in source
    assert "'dispute_id'               => $find_provider_id_meta_like( array( 'dispute' ), array( 'du', 'dp' ) )," in source


def test_native_charge_driver_projects_multicurrency_orders_in_cli_context() -> None:
    source = NATIVE_CHARGE_DRIVER.read_text(encoding="utf-8")

    assert "MultiCurrencyFrontendCurrenciesController" in source
    assert "MultiCurrencyFrontendPricesController" in source
    assert "MultiCurrencyProjectionServiceFactory" in source
    assert "MultiCurrencyRequestContext" in source
    assert "should_register_frontend_hooks(): bool" in source
    assert "set_request_context( $frontend_request_context )" in source
    assert "set_frontend_projection_service" in source
    assert "create_frontend_projection_service" in source
    assert "set_price_projection_service" in source
    assert "create_price_projection_service" in source
    assert "$frontend_currencies_controller->register();" in source
    assert "$frontend_prices_controller->register();" in source
    assert "$order->set_currency( $selected_currency );" in source
    assert "$frontend_prices_controller->add_order_meta( $order->get_id(), $order );" in source


def test_native_charge_driver_forces_store_currency_when_no_currency_is_requested() -> None:
    source = NATIVE_CHARGE_DRIVER.read_text(encoding="utf-8")

    assert "$store_currency = strtoupper( (string) get_option( 'woocommerce_currency' ) );" in source
    assert "wcpay_multi_currency_should_return_store_currency" in source
    assert "wcpay_multi_currency_should_convert_product_price" in source
    assert "add_filter( 'wcpay_multi_currency_should_return_store_currency', '__return_true' );" in source
    assert "add_filter( 'wcpay_multi_currency_should_convert_product_price', '__return_false' );" in source
    assert "$order->set_currency( $store_currency );" in source
    assert "$order->delete_meta_data( '_wcpay_multi_currency_order_exchange_rate' );" in source


def main() -> None:
    test_verify_base_dependencies_are_tracked()
    test_final_evidence_runtime_dependencies_are_tracked()
    test_final_evidence_regression_dependencies_are_tracked()
    test_financial_reconcile_dispute_id_extractor_requires_provider_id_shape()
    test_native_charge_driver_projects_multicurrency_orders_in_cli_context()
    test_native_charge_driver_forces_store_currency_when_no_currency_is_requested()
    print("PASS test_verify_base_dependencies_are_tracked")


if __name__ == "__main__":
    main()
